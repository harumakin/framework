<?php

namespace Illuminate\Foundation\Http;

use Carbon\CarbonInterval;
use DateTimeInterface;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Routing\Pipeline;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\InteractsWithTime;
use InvalidArgumentException;
use Throwable;

class Kernel implements KernelContract
{
    use InteractsWithTime;

    /**
     * The application implementation.
     *
     * @var \Illuminate\Contracts\Foundation\Application
     */
    protected $app;

    /**
     * The router instance.
     *
     * @var \Illuminate\Routing\Router
     */
    protected $router;

    /**
     * The bootstrap classes for the application.
     *
     * @var string[]
     */
    protected $bootstrappers = [
        \Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
        \Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
        \Illuminate\Foundation\Bootstrap\HandleExceptions::class,
        \Illuminate\Foundation\Bootstrap\RegisterFacades::class,
        \Illuminate\Foundation\Bootstrap\RegisterProviders::class,
        \Illuminate\Foundation\Bootstrap\BootProviders::class,
    ];

    /**
     * The application's middleware stack.
     *
     * @var array<int, class-string|string>
     */
    protected $middleware = [];

    /**
     * The application's route middleware groups.
     *
     * @var array<string, array<int, class-string|string>>
     */
    protected $middlewareGroups = [];

    /**
     * The application's route middleware.
     *
     * @var array<string, class-string|string>
     *
     * @deprecated
     */
    protected $routeMiddleware = [];

    /**
     * The application's middleware aliases.
     *
     * @var array<string, class-string|string>
     */
    protected $middlewareAliases = [];

    /**
     * All of the registered request duration handlers.
     *
     * @var array
     */
    protected $requestLifecycleDurationHandlers = [];

    /**
     * When the kernel starting handling the current request.
     *
     * @var \Illuminate\Support\Carbon|null
     */
    protected $requestStartedAt;

    /**
     * The priority-sorted list of middleware.
     *
     * Forces non-global middleware to always be in the given order.
     *
     * @var string[]
     */
    protected $middlewarePriority = [
        \Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
        \Illuminate\Cookie\Middleware\EncryptCookies::class,
        \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
        \Illuminate\Session\Middleware\StartSession::class,
        \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
        \Illuminate\Routing\Middleware\ThrottleRequests::class,
        \Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class,
        \Illuminate\Contracts\Session\Middleware\AuthenticatesSessions::class,
        \Illuminate\Routing\Middleware\SubstituteBindings::class,
        \Illuminate\Auth\Middleware\Authorize::class,
    ];

    /**
     * Create a new HTTP kernel instance.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @param  \Illuminate\Routing\Router  $router
     * @return void
     */
    public function __construct(Application $app, Router $router)
    {
        // Kernelクラスからapp,routerにアクセスできるようにする
        $this->app = $app;
        $this->router = $router;

        // Kernelクラスのrouterの状態とLaravelアプリのRouterの状態を同期する
        $this->syncMiddlewareToRouter();
    }

    /**
     * Handle an incoming HTTP request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function handle($request)
    {
        $this->requestStartedAt = Carbon::now();

        try {
            // POSTメソッドを受け取った時に_methodパラメータを使用して他のメソッドもシミュレートする
            $request->enableHttpMethodParameterOverride();

            // リクエストをルーターを介して送信する
            $response = $this->sendRequestThroughRouter($request);
        } catch (Throwable $e) {
            $this->reportException($e);

            $response = $this->renderException($request, $e);
        }

        // リクエストが正常に処理されたことを示す
        $this->app['events']->dispatch(
            new RequestHandled($request, $response)
        );

        return $response;
    }

    /**
     * Send the given request through the middleware / router.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    protected function sendRequestThroughRouter($request)
    {
        // Illuminate\Http\Requestクラスのインスタンスを受け取る
        $this->app->instance('request', $request);

        /**
         * Requestオブジェクトをサービスコンテナに登録し、アプリ内の他の場所でも同じRequestオブジェクトにアクセスできるようにする
         * サービスコンテナ内の解決済みのリクエストオブジェクトをクリア（リセット？）する。
         * これにより、新しいリクエストが処理される度に新しいリクエストオブジェクトが生成される。
         */
        Facade::clearResolvedInstance('request');

        /**
         * アプリケーションの起動処理をする。
         * これには、アプリケーションの設定の読み込み、サービスプロバイダーの登録、および他の初期化処理が含まれる
         */
        $this->bootstrap();

        /**
         * middlewareを通過させる仕組みを提供する
         * $this->app->shouldSkipMiddleware()がtrueであれば、該当のミドルウェアをスキップする。そうでなければ、配列内のmiddlewareを順番に実行する。
         * middlewareの処理が完了した後、リクエストをルーターにディスパッチ(割り当て)する。
         * ルーターが適切なルートを見つけて、対応するコントローラーを呼ぶ。
         */
        return (new Pipeline($this->app))
                    ->send($request)
                    ->through($this->app->shouldSkipMiddleware() ? [] : $this->middleware)
                    ->then($this->dispatchToRouter());
    }

    /**
     * Bootstrap the application for HTTP requests.
     *
     * @return void
     *
     * アプリケーションの起動処理をする
     */
    public function bootstrap()
    {
        if (! $this->app->hasBeenBootstrapped()) {
            $this->app->bootstrapWith($this->bootstrappers());
        }
    }

    /**
     * Get the route dispatcher callback.
     *
     * @return \Closure
     *
     * リクエストのインスタンスをルーターに割り当てる
     */
    protected function dispatchToRouter()
    {
        return function ($request) {
            $this->app->instance('request', $request);

            return $this->router->dispatch($request);
        };
    }

    /**
     * Call the terminate method on any terminable middleware.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Http\Response  $response
     * @return void
     *
     * アプリケーション内で終了処理を行う
     */
    public function terminate($request, $response)
    {
        // request,responseを渡して、その中の終了処理が必要なmiddlewareを終了させる
        $this->terminateMiddleware($request, $response);

        // サービスコンテナを通じて、アプリケーション全体の終了処理を行う。
        $this->app->terminate();

        // requestStartedAtがnullでない場合、リクエストの処理開始時刻をタイムゾーンを設定し直すための早期リターン。
        if ($this->requestStartedAt === null) {
            return;
        }

        // リクエストの処理開始時刻をタイムゾーンに設定し直す。設定はconfig.phpから読み込まれ、nullの場合はUTCになる。
        $this->requestStartedAt->setTimezone($this->app['config']->get('app.timezone') ?? 'UTC');

        /**
         * リクエストの処理時間を計算する。
         * 配列に登録されているハンドラーを反復処理する。各ハンドラーには、処理時間の閾値と実行する処理が設定されている。
         */
        foreach ($this->requestLifecycleDurationHandlers as ['threshold' => $threshold, 'handler' => $handler]) {
            $end ??= Carbon::now();

            if ($this->requestStartedAt->diffInMilliseconds($end) > $threshold) {
                $handler($this->requestStartedAt, $request, $response);
            }
        }

        // 次のリクエストのためにリクエスト開始処理時間をnullにする。
        $this->requestStartedAt = null;
    }

    /**
     * Call the terminate method on any terminable middleware.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Http\Response  $response
     * @return void
     *
     * Laravelアプリケーション内の終了処理を行う
     */
    protected function terminateMiddleware($request, $response)
    {
        /**
         * $this->app->shouldSkipMiddleware()がtrueの場合、middlewareをスキップする。
         * そうでない場合は、ルートミドルウェアとグローバルミドルウェアをマージして処理対象のミドルウェアのリストを作成する。
         */
        $middlewares = $this->app->shouldSkipMiddleware() ? [] : array_merge(
            $this->gatherRouteMiddleware($request),
            $this->middleware
        );

        // ミドルウェアが文字列である場合、その名前からミドルウェアクラスのインスタンスを解決する
        foreach ($middlewares as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            [$name] = $this->parseMiddleware($middleware);

            $instance = $this->app->make($name);

            // インスタンスにterminateメソッドが存在する場合は、終了処理を実行する
            if (method_exists($instance, 'terminate')) {
                $instance->terminate($request, $response);
            }
        }
    }

    /**
     * Register a callback to be invoked when the requests lifecycle duration exceeds a given amount of time.
     *
     * @param  \DateTimeInterface|\Carbon\CarbonInterval|float|int  $threshold
     * @param  callable  $handler
     * @return void
     */

    /**
     * thresholdはリクエストのライフサイクルの期間が閾値を超えるかどうかを決定する。
     * $handlerは、リクエストのライフサイクルの期間が閾値を超えた場合に実行するコールバック関数
     *
     * このメソッドは、リクエストの処理が長時間になった場合にログを出力する、通知を出すなどの用途に使用する。
     */
    public function whenRequestLifecycleIsLongerThan($threshold, $handler)
    {
        /**
         * thresholdはリクエストのライフサイクルの期間が閾値を超えるかどうかを決定する。
         * $handlerは、リクエストのライフサイクルの期間が閾値を超えた場合に実行するコールバック関数
         *
         * このメソッドは、
         */
        $threshold = $threshold instanceof DateTimeInterface
            ? $this->secondsUntil($threshold) * 1000
            : $threshold;

        $threshold = $threshold instanceof CarbonInterval
            ? $threshold->totalMilliseconds
            : $threshold;

        $this->requestLifecycleDurationHandlers[] = [
            'threshold' => $threshold,
            'handler' => $handler,
        ];
    }

    /**
     * When the request being handled started.
     *
     * @return \Illuminate\Support\Carbon|null
     */

    // リクエストの処理が開始された時刻を記録するために使用されるgetter
    public function requestStartedAt()
    {
        return $this->requestStartedAt;
    }

    /**
     * Gather the route middleware for the given request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    protected function gatherRouteMiddleware($request)
    {
        /**
         * リクエストがルートを持っている場合、リクエストに関連するmiddlewareを収集して、配列で返す。
         */
        if ($route = $request->route()) {
            return $this->router->gatherRouteMiddleware($route);
        }

        return [];
    }

    /**
     * Parse a middleware string to get the name and parameters.
     *
     * @param  string  $middleware
     * @return array
     *
     * ミドルウェアの文字列を解析して、その名前とパラメータを取得する
     */
    protected function parseMiddleware($middleware)
    {
        /**
         * middlewareを最大で2つの部分に分割。
         * 最初の要素はミドルウェアの名前、2番目の要素はパラメータ
         * 2番目の要素（パラメータ）が存在しない場合に空の配列で埋める。これにより、パラメータが存在しない場合でも配列の要素数が確実に2つになる
         *
         * middlewareの設定や使用方法を柔軟にカスタマイズすることが可能になる
         */
        [$name, $parameters] = array_pad(explode(':', $middleware, 2), 2, []);

        if (is_string($parameters)) {
            $parameters = explode(',', $parameters);
        }

        return [$name, $parameters];
    }

    /**
     * Determine if the kernel has a given middleware.
     *
     * @param  string  $middleware
     * @return bool
     */
    public function hasMiddleware($middleware)
    {
        // middlewareを所持しているか確認。
        return in_array($middleware, $this->middleware);
    }

    /**
     * Add a new middleware to the beginning of the stack if it does not already exist.
     *
     * @param  string  $middleware
     * @return $this
     *
     * 既存のmiddlewareスタックの先頭に新しいmiddlewareを追加する
     */
    public function prependMiddleware($middleware)
    {
        // middlewareが既存のスタックから見つからない場合は、新しいmiddlewareを先頭に追加する。
        if (array_search($middleware, $this->middleware) === false) {
            array_unshift($this->middleware, $middleware);
        }

        return $this;
    }

    /**
     * Add a new middleware to end of the stack if it does not already exist.
     *
     * @param  string  $middleware
     * @return $this
     *
     * 既存のmiddlewareスタックの末尾に新しいmiddlewareを追加する
     */
    public function pushMiddleware($middleware)
    {
        // middlewareが既存のスタックから見つからない場合は、新しいmiddlewareを末尾に追加する
        if (array_search($middleware, $this->middleware) === false) {
            $this->middleware[] = $middleware;
        }

        return $this;
    }

    /**
     * Prepend the given middleware to the given middleware group.
     *
     * @param  string  $group
     * @param  string  $middleware
     * @return $this
     *
     * @throws \InvalidArgumentException
     */
    public function prependMiddlewareToGroup($group, $middleware)
    {
        /**
         * 指定されたミドルウェアグループの先頭に指定されたミドルウェアを追加する
         * $groupパラメータが存在しない場合、エラーを返す。
         * このエラーが返された場合、指定したmiddlewareが未定義であることを示す。
         */
        if (! isset($this->middlewareGroups[$group])) {
            throw new InvalidArgumentException("The [{$group}] middleware group has not been defined.");
        }

        // 既存のミドルウェアグループに$middlewareパラメータが存在しない場合、新しく先頭にmiddlewareを追加する
        if (array_search($middleware, $this->middlewareGroups[$group]) === false) {
            array_unshift($this->middlewareGroups[$group], $middleware);
        }

        // ルーターにミドルウェアグループの変更を同期する
        $this->syncMiddlewareToRouter();

        return $this;
    }

    /**
     * Append the given middleware to the given middleware group.
     *
     * @param  string  $group
     * @param  string  $middleware
     * @return $this
     *
     * @throws \InvalidArgumentException
     */
    public function appendMiddlewareToGroup($group, $middleware)
    {
        /**
         * 指定されたミドルウェアグループの末尾に指定されたミドルウェアを追加する
         * $groupパラメータが存在しない場合、エラーを返す。
         * このエラーが返された場合、指定したmiddlewareが未定義であることを示す。
         */
        if (! isset($this->middlewareGroups[$group])) {
            throw new InvalidArgumentException("The [{$group}] middleware group has not been defined.");
        }

        // 既存のミドルウェアグループに$middlewareパラメータが存在しない場合、新しく末尾にmiddlewareを追加する
        if (array_search($middleware, $this->middlewareGroups[$group]) === false) {
            $this->middlewareGroups[$group][] = $middleware;
        }

        // ルーターにミドルウェアグループの変更を同期する
        $this->syncMiddlewareToRouter();

        return $this;
    }

    /**
     * Prepend the given middleware to the middleware priority list.
     *
     * @param  string  $middleware
     * @return $this
     */
    public function prependToMiddlewarePriority($middleware)
    {
        /**
         * $middlewareパラメータが既存の優先度リストに存在しない場合、新しいmiddlewareを既存の優先度リストの先頭に追加する。
         * これは、特定のミドルウェアを他のミドルウェアよりも優先して実行するようにしたい場合に有用
         */

        // 既存のミドルウェア優先度リストに$middlewareパラメータが存在しない場合、先頭に$middlewareパラメータを追加する。
        if (! in_array($middleware, $this->middlewarePriority)) {
            array_unshift($this->middlewarePriority, $middleware);
        }

        // ルーターにミドルウェアグループの変更を同期する
        $this->syncMiddlewareToRouter();

        return $this;
    }

    /**
     * Append the given middleware to the middleware priority list.
     *
     * @param  string  $middleware
     * @return $this
     */
    public function appendToMiddlewarePriority($middleware)
    {
        /**
         * $middlewareパラメータが既存の優先度リストに存在しない場合、新しいmiddlewareを既存の優先度リストの末尾に追加する。
         * これは、特定のミドルウェアを他のミドルウェアよりも優先して実行するようにしたい場合に有用
         */

        // 既存のミドルウェア優先度リストに$middlewareパラメータが存在しない場合、末尾に$middlewareパラメータを追加する。
        if (! in_array($middleware, $this->middlewarePriority)) {
            $this->middlewarePriority[] = $middleware;
        }

        // ルーターにミドルウェアグループの変更を同期する
        $this->syncMiddlewareToRouter();

        return $this;
    }

    /**
     * Sync the current state of the middleware to the router.
     *
     * @return void
     *
     * ミドルウェアの現在の状態をルーターに同期する
     */
    protected function syncMiddlewareToRouter()
    {
        /**
         * ミドルウェアの優先度リストをルーターに反映する
         * routerのミドルウェア優先度リストをKernel.phpのミドルウェア優先度リストに反映している
         */
        $this->router->middlewarePriority = $this->middlewarePriority;

        // ミドルウェアグループの変更をルーターに反映する
        foreach ($this->middlewareGroups as $key => $middleware) {
            $this->router->middlewareGroup($key, $middleware);
        }

        // ミドルウェアエイリアスの変更をルーターに反映する
        foreach (array_merge($this->routeMiddleware, $this->middlewareAliases) as $key => $middleware) {
            $this->router->aliasMiddleware($key, $middleware);
        }
    }

    /**
     * Get the priority-sorted list of middleware.
     *
     * @return array
     *
     * ミドルウェア優先度リストのgetter
     */
    public function getMiddlewarePriority()
    {
        return $this->middlewarePriority;
    }

    /**
     * Get the bootstrap classes for the application.
     *
     * @return array
     *
     * アプリケーションの起動を担うクラスのgetter
     */
    protected function bootstrappers()
    {
        return $this->bootstrappers;
    }

    /**
     * Report the exception to the exception handler.
     *
     * @param  \Throwable  $e
     * @return void
     *
     * 例外の記録、報告に特化したハンドラ
     */
    protected function reportException(Throwable $e)
    {
        $this->app[ExceptionHandler::class]->report($e);
    }

    /**
     * Render the exception to a response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Throwable  $e
     * @return \Symfony\Component\HttpFoundation\Response
     *
     * 例外をレスポンスとして、生成して返すことに特化したハンドラ
     */
    protected function renderException($request, Throwable $e)
    {
        return $this->app[ExceptionHandler::class]->render($request, $e);
    }

    /**
     * Get the application's route middleware groups.
     *
     * @return array
     *
     * ミドルウェアグループのgetter
     */
    public function getMiddlewareGroups()
    {
        return $this->middlewareGroups;
    }

    /**
     * Get the application's route middleware aliases.
     *
     * @return array
     *
     * @deprecated
     *
     * 非推奨のメソッド。ミドルウェアのエイリアスを配列で返す
     */
    public function getRouteMiddleware()
    {
        return $this->getMiddlewareAliases();
    }

    /**
     * Get the application's route middleware aliases.
     *
     * @return array
     *
     * ルートミドルウェアとミドルウェアのエイリアス結合して、配列で返す。
     */
    public function getMiddlewareAliases()
    {
        return array_merge($this->routeMiddleware, $this->middlewareAliases);
    }

    /**
     * Get the Laravel application instance.
     *
     * @return \Illuminate\Contracts\Foundation\Application
     *
     * Laravelアプリケーションのgetter
     */
    public function getApplication()
    {
        return $this->app;
    }

    /**
     * Set the Laravel application instance.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @return $this
     *
     * Laravelアプリケーションのインスタンスを返すsetter
     */
    public function setApplication(Application $app)
    {
        $this->app = $app;

        return $this;
    }
}
