<?php

/**
 * Kura バージョン検証 Middleware サンプル
 *
 * リクエストヘッダー X-Reference-Version でクライアントのバージョンを受け取り、
 * サーバー側の最新バージョンと照合する。
 *
 * VersionResolverInterface は CachedVersionResolver でラップ済みを想定。
 * → DB/CSV への問い合わせは5分に1回（APCu キャッシュ）。
 *
 * 使い方:
 *   // app/Http/Kernel.php or bootstrap/app.php
 *   ->withMiddleware(function (Middleware $middleware) {
 *       $middleware->api(append: [KuraVersionMiddleware::class]);
 *   })
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Kura\Contracts\VersionResolverInterface;
use Symfony\Component\HttpFoundation\Response;

class KuraVersionMiddleware
{
    private const HEADER = 'X-Reference-Version';

    public function __construct(
        private readonly VersionResolverInterface $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $serverVersion = $this->resolver->resolve();

        if ($serverVersion === null) {
            // バージョンテーブル未設定 → 素通し
            return $next($request);
        }

        // レスポンスに最新バージョンを付与（クライアントが次回送れるように）
        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $serverVersion);

        // クライアントからバージョンが来ている場合は照合
        $clientVersion = $request->header(self::HEADER);

        if ($clientVersion !== null && $clientVersion !== $serverVersion) {
            // バージョン不一致 → レスポンスヘッダーで通知
            // アプリ側で 409 にするか、ヘッダーで通知だけするかはプロジェクト次第
            $response->headers->set('X-Reference-Version-Mismatch', 'true');
        }

        return $response;
    }
}
