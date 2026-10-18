@AGENTS.md

#  まちチケ 

主催者がイベントと券種を登録し、参加者が Stripe Checkout でチケットを買うアプリ 

## このプロジェクトの決まり

- 画面は Livewire 4 の単一ファイルコンポーネント（`resources/views/pages/**/⚡*.blade.php`）で作る。Volt は使わない
- UI は Flux UI の Free 版のコンポーネントだけを使う（Pro 専用は使わない）。無いものは Tailwind で作る
- 文言は日本語。日付は `isoFormat('M月D日(ddd) HH:mm')`、金額は「3,000円」の形式
- 認可は `app/Policies/` のポリシーで行い、画面側では `$this->authorize()` を使う
- 外部サービス（Stripe など）に触るコードは `app/Services/` に閉じ込める。テストではそのクラスをモックする
- 機能を追加したら `tests/Feature/` に Pest テストを追加し、`/check` が通ることを確認する
- 開発コマンドは Sail 経由（`sail artisan ...`、`sail composer ...`、`sail npm ...`）。ホストの php や composer は使わない
- ビューを追加・変更したら `sail npm run build` を実行する（Tailwind がクラスを拾うため）

## 作業の進め方

- 変更の前に、何をどのファイルに書くかを短く説明してから実装する
- 1回の指示で扱う範囲は1機能まで。大きい依頼は分割案を先に出す
- 既存のファイルの書き方に合わせる（同じ階層のファイルを先に読む）
- `.env` の値、API キー、パスワードはコードやコミットに含めない
- 失敗したコマンドは、原因の推測と対処案を1つずつ示してから再実行する

## 設計

詳細: [データベース設計](doc/design/database.md)、[画面一覧](doc/design/screens.md)

### 用語

- イベント（`events`）: 主催者が登録する催し。状態は `draft`（下書き）/ `published`（公開）/ `unpublished`（管理者が非公開にしたもの）
- 券種（`ticket_types`）: イベントごとの価格・定員・販売期間の区分（一般、学生 など）
- 注文（`orders`）: 1回の購入。1注文 = 1イベント。状態は `pending`（支払い待ち）→ `paid`（支払い済み）
- 注文明細（`order_items`）: 注文の中の券種と枚数
- チケット（`tickets`）: `paid` になったとき、枚数分を1枚ずつ発行する。固有の `code`（ULID）を持つ

### データベース

- 主催者は `events.organizer_id`、注文者は `orders.user_id`（どちらも `users.id`）。ロールは `users.role`
- 状態とロールは `string` のカラム＋PHP の Enum（`UserRole`、`EventStatus`、`OrderStatus`）でキャストする
- 金額は円の整数。明細には注文時点の単価（`order_items.unit_price`）を保存し、券種の価格を後から参照しない
- 残り枚数はカラムに持たず毎回計算する: 定員 −（`paid` の枚数 + 作成から31分以内の `pending` の枚数）。31分は Stripe の決済ページの最短期限（30分）に合わせたもの
- `paid` への更新は「`pending` のときだけ」の条件付き更新にする（Webhook と完了ページの二重処理でチケットを二重発行しない）
- 外部キーは、記録を残すものは restrict（主催者・注文者・注文のイベント・明細の券種）、親の一部は cascade（券種・明細・チケット）
- イベントの削除は、`paid` の注文が無いときだけ。`pending` の注文を消してから消す（トランザクション）
- 画像は `public` ディスクの `events/` に保存し、そのパスを `events.image_path` に持つ

### 画面

- 誰でも見られる: イベント一覧 `/`、詳細 `/events/{event}`。下書きと非公開は一覧に出さず、詳細は所有者と管理者以外に 404
- 主催者: `/organizer/events` 以下（一覧・登録・編集・注文と売上）。他人のイベントは 403
- 参加者: `/orders`（注文一覧）、`/orders/{order}`（注文詳細）、`/orders/{order}/complete`、`/orders/{order}/cancel`。注文は本人・そのイベントの主催者・管理者だけが見られ、他人は 403
- Webhook は `POST /stripe/webhook`（CSRF の対象外、署名を検証する）
