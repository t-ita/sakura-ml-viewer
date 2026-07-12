# ML記事ビューア README（デプロイ手順書）

対象: さくらインターネット ライトプラン（PHP CGIモード / MySQL・CRON・SSH利用不可）。
設計の詳細は [DESIGN.md](DESIGN.md) を参照。本書はビルド・デプロイ・動作確認の手順書。

---

## 1. 全体構成のおさらい

```
backend/public/   → FTPで ~/www/ml-viewer/        へアップロード（公開領域）
backend/app/      → FTPで ~/mlviewer-app/          へアップロード（非公開・docルート外）
frontend/dist/    → ローカルでビルドし ~/www/ml-viewer/ へアップロード（backend/public/ の中身と合流）
backend/tests/    → アップロード不要（ローカル確認専用）
DESIGN.md, README.md, .git など → アップロード不要
```

`~/mlviewer-data/`（SQLite DB・セッション・ロックファイル置き場）はリポジトリに含まれない。サーバー側で新規作成する（§4）。

---

## 2. ローカルでの準備

### 2.1 必要なツール

- Node.js 20以上（開発時はv24系で動作確認済み）
- PHP 8.1以上 + `pdo_sqlite` / `sqlite3` / `mbstring` 拡張（ローカルでのユニットテスト・動作確認用。サーバー側はさくらの実行環境を使うのでローカルにPHPが無くても納品物の作成自体は可能だが、動作確認には強く推奨）

### 2.2 フロントエンドのビルド

```bash
cd frontend
npm install
npm run build
```

- 成果物は `frontend/dist/`（`index.html` と `assets/`）に生成される。
- `vite.config.ts` で `base: './'` を指定しているため、生成されるアセット参照はすべて相対パス。`~/www/ml-viewer/` 以外のサブディレクトリに配置してもそのまま動作する。
- ビルド時に `tsc -b` の型チェックも走る。エラーが出た場合はデプロイ前に必ず解消すること。

### 2.3 バックエンドの設定ファイル作成

> **重要**: `backend/app/config.php` はリポジトリに含まれない（`.gitignore`対象）。デプロイのたびに、以下の手順で**そのデプロイ先の実際の値**を使って新規作成すること。ローカルの動作確認用に作った`config.php`（テスト用のパスを指しているもの）を誤ってそのまま本番へアップロードすると、fmlデータやDBのパスが存在せず**全リクエストがフェイルクローズで503になる**（actives読込失敗時の仕様どおりの挙動だが、原因が分かりにくい）。ローカル確認用と本番デプロイ用で`config.php`を使い回さないこと。

```bash
cp backend/app/config.sample.php backend/app/config.php
```

`backend/app/config.php` を開き、以下を**アップロード先アカウントの実際の値**に書き換える。

| キー | 内容 |
|---|---|
| `ml_name` | 対象MLの名前（fmlのML名） |
| `spool_dir` | `/home/ACCOUNT/fml/spool/ml/<ML名>/spool` |
| `actives_file` | `/home/ACCOUNT/fml/spool/ml/<ML名>/actives` |
| `seq_file` | `/home/ACCOUNT/fml/spool/ml/<ML名>/seq` |
| `db_path` | `/home/ACCOUNT/mlviewer-data/mlviewer.sqlite` |
| `session_path` | `/home/ACCOUNT/mlviewer-data/sessions` |
| `locks_dir` | `/home/ACCOUNT/mlviewer-data/locks` |
| `log_file` | `/home/ACCOUNT/mlviewer-data/app.log` |
| `base_url` | `https://<独自ドメイン>/ml-viewer`（末尾スラッシュなし） |
| `mail_from` / `mail_from_name` | パスワード設定メールの送信元（自ドメインのアドレスにしSPFアライメントを崩さないこと） |

`config.php` は`.gitignore`対象だが**FTPアップロード対象**（`backend/app/` 一式に含めてアップロードする）。「Git管理外＝チェックしなくてよい」ではない点に注意し、アップロード前に必ず中身（特に各パスと`base_url`）を目視確認すること。

---

## 3. FTPアップロード一覧

| ローカルのパス | アップロード先 | 備考 |
|---|---|---|
| `backend/public/` 配下の全ファイル（`.htaccess` 含む） | `~/www/ml-viewer/` | |
| `frontend/dist/` 配下の全ファイル（`index.html`, `assets/`） | `~/www/ml-viewer/` | `backend/public/` の中身と統合して1つの公開ディレクトリにする |
| `backend/app/` 配下の全ファイル（§2.3で作成した**そのデプロイ先用**の`config.php`を含める。`config.sample.php`は任意） | `~/mlviewer-app/` | **docルート外**。Web経由でアクセスできない場所に置くこと |

アップロードしないもの: `frontend/node_modules/`, `frontend/src/`（`.tsx`/`.ts`ソース）, `backend/tests/`, `DESIGN.md`, `README.md`, `.git/`, `.claude/`。

最終的なサーバー上のディレクトリイメージ:

```
/home/ACCOUNT/
├── www/ml-viewer/
│   ├── index.html
│   ├── assets/...
│   ├── .htaccess
│   └── api/
│       ├── .htaccess
│       └── index.php
├── mlviewer-app/
│   ├── config.php
│   ├── bootstrap.php
│   ├── db.php
│   ├── router.php
│   ├── auth.php
│   ├── api.php
│   ├── indexer.php
│   ├── mail_parser.php
│   ├── actives.php
│   ├── ratelimit.php
│   ├── csrf.php
│   ├── mailer.php
│   └── json.php
├── mlviewer-data/            ← サーバー側で新規作成（§4）
│   ├── mlviewer.sqlite        （初回アクセス時に自動生成）
│   ├── dummy_hash.txt         （初回アクセス時に自動生成。ログイン試行の時間差対策用ダミーハッシュ。機密情報ではない）
│   ├── sessions/
│   └── locks/
└── fml/spool/ml/<ML名>/       ← 既存のfmlデータ（読み取り専用・変更しない）
```

---

## 4. サーバー側で確認・設定する項目

### 4.1 `mlviewer-data/` ディレクトリの作成とパーミッション

FTPクライアントやさくらのサーバーコントロールパネルのファイルマネージャーで、ドキュメントルート外に作成する。

```
mkdir -p /home/ACCOUNT/mlviewer-data
chmod 700 /home/ACCOUNT/mlviewer-data
```

- `mlviewer.sqlite`、`sessions/`、`locks/` は初回アクセス時にアプリが自動生成する（`db.php` / `bootstrap.php` 参照）。事前に手動作成する必要はない。
- 生成後、`mlviewer.sqlite` のパーミッションが600になっていることを確認する（アプリ側で作成時に `chmod` 済みだが、FTP経由での取得・移動を行った場合は再確認すること）。
- `mlviewer-app/config.php` は600を推奨。

### 4.2 PHPのバージョン・拡張モジュール

さくらのサーバーコントロールパネルで実行するPHPのバージョンを **8.1以上** に設定する（`match`式や `never` 戻り値型など8.0〜8.1の言語機能を使用しているため）。以下の拡張がロードされていることを確認する（さくらの標準構成であれば通常デフォルトで有効）。

- `pdo_sqlite`
- `mbstring`

### 4.3 HTTPS強制・セキュリティヘッダ

`backend/public/.htaccess` にHTTPSへのリダイレクトとセキュリティヘッダ（`X-Content-Type-Options`, `X-Frame-Options`, `Content-Security-Policy` 等）を設定済み。サーバーが独自SSL証明書設定済みであることを確認するだけでよい（さくらの無料独自SSLなどを事前に有効化しておくこと）。

`mod_headers` が無効な環境では `Header` ディレクティブが無視される（500エラーにはならないが、セキュリティヘッダが付与されない）。さくらの標準Apache構成では有効なはずだが、念のため導入後にブラウザの開発者ツールでレスポンスヘッダを確認すること。

### 4.4 `.htaccess` の rewrite が使えない場合のフォールバック

`backend/public/api/.htaccess` の `mod_rewrite` が何らかの理由で無効な場合、フロントエンドは自動でフォールバックしない。その場合は `frontend/src/api/client.ts` の `API_BASE` の扱いを見直す必要があるが、さくらのレンタルサーバーの標準構成では `mod_rewrite` は有効なため通常は対応不要。

### 4.5 初回のフルインデックス実行

CRONが使えないため、記事インデックスは **`GET /articles`（一覧画面表示）のたびに最大50件・5秒ぶんだけ前進する** 遅延増分方式（`DESIGN.md` §3.3）。fmlの記事数が多いMLをデプロイした直後は、以下の手順で完了させる。

1. デプロイ後、対象MLのメンバーとしてログインする。
2. 画面上部に **「記事インデックスを構築中です（残り約N件）」** バナーが表示される。この状態のまま画面を開いたまま待つ（5秒間隔で自動的に進捗を確認・反映する）。
3. `残り件数` が減っていき、0になるとバナーが自動的に消える。
4. 記事数が非常に多い場合（数万通など）、バナー表示中に何度か一覧画面をリロードする（1リクエストごとに最大50件処理されるため、リロードの都度確実に前進する）。
5. 進捗は `GET /ml-viewer/api/index/status` でも確認できる（`{"indexed_max":..., "seq":..., "pending":...}` が返る）。

**503対策**: 1リクエストあたりの処理を50件・5秒に制限しているため、共用サーバーでの高負荷503は基本的に発生しない設計。それでも大量の初回インデックスで負荷が気になる場合は、`config.php` の `index_batch_size` / `index_time_budget_s` を一時的に小さくして様子を見ること。

---

## 5. ローカルでのバックエンド動作確認（任意・推奨）

サーバーにアップロードする前に、ローカルのPHPで一通り動作確認できる。

### 5.1 ユニットテスト

```bash
php backend/tests/run_tests.php
```

依存ゼロの軽量テストランナー。`mail_parser.php`（fmlメールパーサ、fixtureメール5種）、`actives.php`（activesファイル解析）、`api.php`（LIKE検索エスケープ・日付境界・スニペット生成）、`auth.php`（トークン生成・ハッシュ）、`db.php`（マイグレーションの冪等性）、`ratelimit.php`（レート制限ロジック）をカバーする。実行の都度 `backend/tests/tmp/` に一時SQLite DBを作り直すため、外部状態に依存しない。

### 5.2 PHP組み込みサーバーでの手動確認

```bash
# backend/app/config.php を作成し、テスト用のspool/actives/seq/db_pathを指すよう設定した上で
php -S localhost:8099 -t backend/public
```

`backend/public/api/index.php` は `?route=/articles` のようなクエリ文字列フォールバックに対応しているため、`mod_rewrite` が使えない組み込みサーバーでも動作確認できる（例: `curl "http://localhost:8099/api/index.php?route=/auth/session"`）。

**この手順で作った`config.php`は動作確認専用**。§2.3・§3で本番デプロイする際は、テスト用パスの入ったこの`config.php`を使い回さず、必ず本番アカウントの実際の値で新規に作り直すこと（作業が終わったらこの`config.php`は削除するか、`backend/app/`の外に退避しておくと事故が防げる）。

### 5.3 フロントエンドの開発サーバー

```bash
cd frontend
npm run dev
```

`vite.config.ts` に開発用プロキシを設定済み（`/api/*` を `http://localhost:8099` へ転送し、上記の `?route=` 形式に自動変換する）。バックエンドを `php -S localhost:8099 -t backend/public` で起動した状態で `npm run dev` すれば、実際のAPIと繋いだ状態でフロントエンドの動作確認ができる。

---

## 6. デプロイ後の手動確認チェックリスト

CI・SSHが使えない環境のため、デプロイ後は以下を手動で確認する。

### 認証・アクセス制御

- [ ] MLの `actives` に載っていないメールアドレスでは、パスワード設定メールが届かない（届く・届かないに関わらず画面の応答文言は同一であること）
- [ ] MLメンバーが初回にパスワード設定メールを受け取り、リンクからパスワードを設定できる
- [ ] 設定したパスワードでログインできる
- [ ] 誤ったパスワードでログインするとエラーになり、かつ非会員のメールアドレスでログインした場合と同じエラーメッセージが表示される（列挙防止）
- [ ] ログイン中に自分でパスワードを変更できる
- [ ] ログアウトすると再度ログイン画面に戻り、ログアウト後は記事一覧にアクセスできない
- [ ] **退会テスト**: メンバーでログインした状態で `actives` からそのアドレスを削除し、その状態で画面を操作すると即座にログアウト相当の扱いになる（ページ遷移や再読み込みで401/403になる）
- [ ] ログイン試行を短時間に繰り返すと429（レート制限）になる

### 記事閲覧・検索

- [ ] 記事一覧が新着順に表示される
- [ ] キーワード検索（件名・本文）が機能する
- [ ] 送信者検索が機能する
- [ ] 日付範囲検索が機能する（Popoverのカレンダーから選択できる）
- [ ] 記事詳細で本文がプレーンテキストとして表示され、URLが自動リンク化されている
- [ ] 添付ファイルがある記事でファイル名・サイズが表示される（添付ファイル自体はダウンロードできない仕様どおりであることを確認）
- [ ] 日本語件名・本文（ISO-2022-JP等の旧エンコーディングを含む）が文字化けせず表示される
- [ ] 「さらに読み込む」で次ページが追記表示される

### インデックス・パフォーマンス

- [ ] デプロイ直後、インデックス構築中バナーが表示され、進捗とともに自動的に消える（§4.5）
- [ ] 大量データ投入後も503エラーが発生しない

### セキュリティ

- [ ] `https://` へのリダイレクトが機能する
- [ ] `/mlviewer-data/` や `mlviewer.sqlite` にブラウザから直接アクセスできない（`https://.../mlviewer-data/mlviewer.sqlite` 等が404/403になる。そもそもdocルート外なので到達不能なはず）
- [ ] ブラウザの開発者ツールでCookieを確認し、セッションCookieに `Secure` / `HttpOnly` / `SameSite=Lax` が付与されている
- [ ] レスポンスヘッダに `X-Content-Type-Options: nosniff` 等が付与されている

---

## 7. 既知の制約・今後の課題

- 添付ファイルの実体ダウンロードは非対応（DESIGN.md §0 の判断による。ファイル名・MIME種別・サイズの表示のみ）。
- キーワード検索はSQLiteのLIKE全走査（FTS5未使用）。ML規模が非常に大きくなった場合は検索が遅くなる可能性がある（`api.php` の `mlv_build_article_where` に隔離済みなので、必要になれば移行しやすい設計にしてある）。
- パスワードリセットメールは `mail()` 関数依存。さくらの送信制限・spamフィルタ設定によっては到達しないことがあるため、実運用前に実際に受信確認を行うこと。
