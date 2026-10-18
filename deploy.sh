#!/usr/bin/env bash
set -e

echo "==> アプリのディレクトリへ移動"
cd /var/www/ticket-app

echo "==> main ブランチの最新を取得"
git pull origin main

echo "==> Composer の依存をインストール（本番用）"
composer install --no-dev --optimize-autoloader --no-interaction

echo "==> フロントエンドをビルド"
npm ci && npm run build

echo "==> マイグレーションを実行"
php artisan migrate --force

echo "==> キャッシュを作成（optimize）"
php artisan optimize

echo "==> デプロイ完了"
