# ML記事ビューア 設計書（DESIGN.md）

- 対象環境: さくらインターネット ライトプラン（PHP CGIモード / MySQL不可 / CRON不可 / SSH不可 / FTPデプロイのみ）
- データソース: fml 4系のスプール（読み取り専用）
- 作成フェーズ: Phase 1（設計） / 担当: Fable 5
- 本書のパス表記では、さくらのアカウント名を `ACCOUNT`、対象ML名を `MLNAME` と書く。実際の値は `config.php` で定義する。

## 改訂履歴

| 版 | 内容 |
|---|---|
| 初版 | Phase 1設計 |
| 改訂1 | Phase 3レビュー結果の反映（§5.4列挙防止の記述を実装実態に合わせて修正、§7.3にダミーハッシュ生成方式・ログイン時CSRF再生成を追記） |
| 改訂2 | **UI刷新**: 3ペイン構成を廃止し「コントロールパネル＋記事カード一覧（インライン展開）」に変更（§6全面改訂）。検索を常時表示フィルタから検索ダイアログに集約し、検索条件に記事番号範囲を追加（§4.4に`id_from`/`id_to`）。ヘッダーのブランディング（ロゴ/タイトル）規約を追加（§6.6、§1.2） |
| 改訂3 | **管理者機能（読み取り専用）追加**: adminsファイルによる管理者判定（§5.8）、管理系API `GET /admin/users`・`GET /admin/articles`（§4.5）、管理画面「ユーザー管理」「記事管理」（§6.9）。DBスキーマ変更なし・変更系エンドポイントなし |

---

## 0. 全体アーキテクチャ概要

```
ブラウザ (React SPA)
   │  HTTPS / JSON (fetch, Cookieセッション + CSRFトークン)
   ▼
/home/ACCOUNT/www/ml-viewer/          ← 公開領域（ドキュメントルート配下）
   ├── index.html, assets/*           ← Viteビルド成果物（静的）
   └── api/index.php                  ← APIフロントコントローラ（PHP CGI）
        │ require
        ▼
/home/ACCOUNT/mlviewer-app/           ← 非公開: PHPライブラリ本体
        │ PDO(SQLite) / ファイル読み取り
        ▼
/home/ACCOUNT/mlviewer-data/          ← 非公開: SQLite DB・セッション・ロック
/home/ACCOUNT/fml/spool/ml/MLNAME/    ← 非公開: fmlデータ（読み取り専用）
```

設計上の柱:

1. **公開領域には「静的アセット」と「薄いエントリPHP」だけを置く。** ロジック・DB・秘密情報はすべてドキュメントルート（`~/www`）の外。
2. **CRONが無いので、インデックス更新はAPIリクエスト便乗型の遅延増分処理**（バッチ上限つき・`flock` 排他）。
3. **会員資格の正はfmlの `actives` ファイル**。DBの `users` テーブルは「パスワード等の付随情報」しか持たない。退会者は `actives` から消えた瞬間に認証・セッション継続の両方で弾かれる。

### 確定済み技術方針からの変更点

原則としてすべて踏襲する。追加で確定させた事項（変更ではなく具体化）:

- キーワード検索はSQLiteの **`LIKE`（ESCAPE付き）** で行い、FTS5は使わない。理由: さくらのPHPに同梱されるSQLiteでFTS5（特に日本語に必要なtrigramトークナイザ）が有効である保証がなく、ML規模（数千〜数万通）ならLIKE全走査で実用上十分。将来FTS5 trigramが使えると確認できたら移行できるよう、検索処理は1関数に隔離する。
- PHPセッションの保存先を共有 `/tmp` ではなく `mlviewer-data/sessions/`（パーミッション700）に変更する。共用サーバで他ユーザーからセッションファイルが見えるリスクを避けるため。
- 添付ファイルの**ダウンロード機能はv1では実装しない**。記事詳細に添付ファイル名とサイズのみ表示する。理由: 閲覧専用という要件の範囲で必須ではなく、添付配信はContent-Type偽装・XSS・パストラバーサルの攻撃面を大きく増やすため。将来対応はDESIGN改訂のうえPhase 1からやり直す。

---

## 1. リポジトリ構成

### 1.1 リポジトリ（ローカル開発）のディレクトリ構成

```
ml-viewer/
├── DESIGN.md                      # 本書
├── README.md                      # ビルド・デプロイ・手動確認手順（Phase 2で作成）
├── backend/
│   ├── public/                    # → FTPで ~/www/ml-viewer/ へ
│   │   ├── .htaccess              # HTTPS強制・APIルーティング・セキュリティヘッダ
│   │   └── api/
│   │       ├── .htaccess          # /api/* → index.php のrewrite
│   │       └── index.php          # フロントコントローラ（数行。app/を require するだけ）
│   ├── app/                       # → FTPで ~/mlviewer-app/ へ（非公開）
│   │   ├── config.sample.php      # 設定ひな形（コピーして config.php を作る）
│   │   ├── bootstrap.php          # 設定読込・エラーハンドラ・セッション初期化
│   │   ├── db.php                 # PDO接続・冪等マイグレーション
│   │   ├── router.php             # ルーティングとディスパッチ
│   │   ├── auth.php               # ログイン/ログアウト/トークン/パスワードAPI
│   │   ├── api.php                # 記事一覧・詳細・検索API
│   │   ├── admin.php              # 管理者向け読み取り専用API（改訂3。§4.5）
│   │   ├── indexer.php            # 遅延増分インデクサー（flock排他）
│   │   ├── mail_parser.php        # 生メールのMIMEパーサ（自前実装）
│   │   ├── actives.php            # activesファイルの読取・突合、admins判定（§5.8）
│   │   ├── ratelimit.php          # レート制限
│   │   ├── csrf.php               # CSRFトークン発行・検証
│   │   ├── mailer.php             # mail() ラッパ（From/エンベロープ制御）
│   │   └── json.php               # JSON入出力・エラーレスポンス共通処理
│   └── tests/                     # ローカル実行のみ（アップロードしない）
│       ├── run_tests.php          # 依存ゼロの軽量テストランナー
│       ├── fixtures/              # テスト用の生メールサンプル
│       └── *_test.php
├── frontend/
│   ├── package.json / vite.config.ts / tsconfig.json / components.json
│   ├── index.html
│   └── src/
│       ├── main.tsx / App.tsx
│       ├── api/                   # API呼び出し層（型定義含む）
│       ├── components/            # 画面コンポーネント（§6参照）
│       ├── components/ui/         # shadcn/ui生成コンポーネント
│       ├── hooks/
│       └── lib/
└── deploy/
    └── ftp-manifest.md            # アップロード対象と配置先の対応表（README別紙）
```

### 1.2 サーバー上の配置（FTPアップロード後）

| サーバーパス | 内容 | 公開 |
|---|---|---|
| `~/www/ml-viewer/index.html`, `~/www/ml-viewer/assets/*` | Viteビルド成果物 | ○ |
| `~/www/ml-viewer/.htaccess`, `~/www/ml-viewer/api/` | HTTPS強制・API入口 | ○（PHPは実行のみ） |
| `~/www/ml-viewer/branding/`（任意） | ヘッダーのロゴ/タイトル（`logo.png` / `title.txt`。§6.6の規約。運用者がFTPで直接設置し、リポジトリには含めない） | ○ |
| `~/mlviewer-app/` | PHPライブラリ一式 + `config.php` | ×（docルート外） |
| `~/mlviewer-data/mlviewer.sqlite`（+ `-wal`, `-shm`） | SQLite DB | ×（docルート外） |
| `~/mlviewer-data/sessions/` | PHPセッションファイル（700） | × |
| `~/mlviewer-data/locks/` | flock用ロックファイル | × |
| `~/mlviewer-data/admins`（任意） | 管理者リスト（1行1メールアドレス。§5.8。無ければ管理機能は無効のまま） | × |
| `~/fml/spool/ml/MLNAME/` | fmlデータ（既存・**読み取り専用**） | × |

### 1.3 FTPでアップロードするもの / しないもの

| アップロードする | アップロードしない |
|---|---|
| `backend/public/` の全ファイル（`.htaccess` 含む） | `frontend/src/`, `*.tsx`, `*.ts` ソース一式 |
| `backend/app/`（`config.sample.php` から作成した `config.php` 含む） | `node_modules/` |
| `frontend/dist/` のビルド成果物（→ `~/www/ml-viewer/`） | `backend/tests/` |
| — | `DESIGN.md`, `README.md`, `.git` などリポジトリ管理物 |

- Composer依存は**採用しない**（自前実装で完結、§3.1参照）。将来採用する場合はローカルで `composer install` した `vendor/` を `~/mlviewer-app/vendor/` にアップロードする方式とする。
- `config.php` はGit管理せず（`.gitignore`）、`config.sample.php` をコピーしてアカウント固有値（`ACCOUNT`、ML名、ベースURL、送信元アドレス）を記入する。秘密鍵の類は持たない設計（セッション・トークンはすべてDB/ファイルベース）。

---

## 2. DBスキーマ確定版

SQLite（PDO, `PRAGMA journal_mode=WAL`, `PRAGMA foreign_keys=ON`, `PRAGMA busy_timeout=5000`）。
DBファイル: `/home/ACCOUNT/mlviewer-data/mlviewer.sqlite`（ディレクトリ705→原則700、§7.5参照。ファイル自体は600）。

### 2.1 `articles` — 記事インデックス

| カラム | 型 | 制約 | 説明 |
|---|---|---|---|
| `id` | INTEGER | PRIMARY KEY | fmlの記事連番（spool内ファイル名）と同一。AUTOINCREMENTにしない |
| `message_id` | TEXT | NULL可 | `Message-Id` ヘッダ（重複検知・参照用） |
| `subject` | TEXT | NOT NULL DEFAULT '' | MIMEデコード・UTF-8正規化済み件名 |
| `from_addr` | TEXT | NOT NULL DEFAULT '' | 送信者メールアドレス（小文字正規化） |
| `from_name` | TEXT | NOT NULL DEFAULT '' | 送信者表示名（デコード済み。無ければ空） |
| `date_epoch` | INTEGER | NOT NULL DEFAULT 0 | `Date` ヘッダのUNIX秒。パース不能時はファイルmtime |
| `body_text` | TEXT | NOT NULL DEFAULT '' | プレーンテキスト化済み本文（UTF-8） |
| `attachments_json` | TEXT | NOT NULL DEFAULT '[]' | 添付メタのJSON配列 `[{"filename","mime","size"}]`。実体は保存しない |
| `parse_status` | TEXT | NOT NULL DEFAULT 'ok' | `ok` / `partial`（一部デコード失敗） / `error`（ファイル欠損・破損） |
| `indexed_at` | INTEGER | NOT NULL | インデックス登録時刻（UNIX秒） |

```sql
CREATE INDEX idx_articles_date  ON articles(date_epoch DESC);
CREATE INDEX idx_articles_from  ON articles(from_addr);
```

- キーワード検索は `subject LIKE ? ESCAPE '\'` OR `body_text LIKE ?` の全走査（§4.4）。インデックスは効かないが規模的に許容。
- 欠番（削除された記事ファイル）も `parse_status='error'` の行を入れて番号を埋める。これにより「seqとMAX(id)の差分」だけで増分判定でき、毎回の欠番再スキャンを避ける。`error` 行は一覧・検索から除外する。

### 2.2 `users` — パスワード等の付随情報（会員資格の正はactives）

| カラム | 型 | 制約 | 説明 |
|---|---|---|---|
| `email` | TEXT | PRIMARY KEY | 小文字正規化済みメールアドレス |
| `password_hash` | TEXT | NULL可 | `password_hash(PASSWORD_DEFAULT)`。未設定はNULL |
| `created_at` | INTEGER | NOT NULL | |
| `password_updated_at` | INTEGER | NULL可 | |
| `last_login_at` | INTEGER | NULL可 | |

- 行は「初回のトークン発行時」に作成する（activesの全員分を先回りで作らない）。
- `actives` に存在しないアドレスは、この表に行があってもログイン不可（毎回突合、§3.2）。

### 2.3 `tokens` — パスワード設定・リセット用トークン

| カラム | 型 | 制約 | 説明 |
|---|---|---|---|
| `id` | INTEGER | PRIMARY KEY AUTOINCREMENT | |
| `email` | TEXT | NOT NULL | 対象アドレス（小文字正規化） |
| `token_hash` | TEXT | NOT NULL UNIQUE | トークン平文のSHA-256（hex）。**平文は保存しない** |
| `purpose` | TEXT | NOT NULL DEFAULT 'set_password' | 初回設定とリセットは同一purposeで扱う |
| `expires_at` | INTEGER | NOT NULL | 発行から**24時間** |
| `used_at` | INTEGER | NULL可 | 使用済み時刻。非NULLなら再利用不可 |
| `created_at` | INTEGER | NOT NULL | |

```sql
CREATE INDEX idx_tokens_email ON tokens(email);
```

- 新規発行時、同一emailの未使用トークンをすべて失効させる（`used_at` を発行時刻で埋める）＝有効トークンは常に最大1つ。
- 期限切れ・使用済み行は、発行処理のついでに30日超過分をDELETE（掃除もリクエスト便乗）。

### 2.4 `rate_limits` — レート制限カウンタ

| カラム | 型 | 制約 | 説明 |
|---|---|---|---|
| `bucket` | TEXT | PRIMARY KEY | 例: `login:e:<email>` / `login:ip:<ip>` / `token:e:<email>` / `token:ip:<ip>` |
| `window_start` | INTEGER | NOT NULL | 固定ウィンドウ開始時刻 |
| `count` | INTEGER | NOT NULL | ウィンドウ内の試行回数 |

- 固定ウィンドウ方式（§5.6）。ウィンドウ超過時はUPSERTでリセット。古い行は書き込み時に確率的に掃除。

### 2.5 `schema_version` とマイグレーション方針

```sql
CREATE TABLE IF NOT EXISTS schema_version (version INTEGER NOT NULL);
```

- `db.php` に `MIGRATIONS = [1 => [...SQL...], 2 => [...], ...]` の連想配列を持つ。
- 全APIリクエストの接続直後に現在versionを読み、未適用があれば `flock`（`locks/migrate.lock`）を**ブロッキング取得**してから、バージョンごとに `BEGIN IMMEDIATE` → SQL群 → `UPDATE schema_version` → `COMMIT`。二重チェック（ロック取得後に再読取）で多重適用を防ぐ。
- version=1 が上記全テーブルのCREATE。**将来のカラム追加手順**: `MIGRATIONS` に次番号で `ALTER TABLE ... ADD COLUMN ...` を追記してFTPアップロードするだけ。次のリクエストで自動適用される（SSH不要）。SQLiteのALTERはADD COLUMN中心なので、それを超える変更（型変更等）は「新テーブル作成→INSERT SELECT→RENAME」をマイグレーションSQL内で完結させる。

---

## 3. fmlデータ読み取り仕様

### 3.1 生メールファイルのパース方針（自前実装）

対象: `/home/ACCOUNT/fml/spool/ml/MLNAME/spool/<N>`（N=1始まりの連番、RFC822生メール）。

**自前実装を選ぶ理由**: さくら共用サーバでは `mailparse` 拡張が使えず、Composerライブラリ（例: zbateson/mail-mime-parser）はvendorが大きくFTPデプロイと相性が悪い。必要機能は「閲覧用のヘッダ4種＋本文のテキスト化」に限られるため、`mbstring` 標準機能ベースの限定パーサで十分かつ監査しやすい。

`mail_parser.php` の仕様:

1. **ヘッダ/本文分離**: 最初の空行まで。ヘッダは折返し（先頭が空白の継続行）をunfold。
2. **encoded-word（RFC2047）デコード**: `Subject` / `From` に対し `mb_decode_mimeheader()` を使用（`mb_internal_encoding('UTF-8')` 前提）。デコード失敗時は生値をそのまま保持し `parse_status='partial'`。
3. **From分解**: `表示名 <addr>` / `addr (コメント)` / 素のaddr の3形式を正規表現で分解。addrは小文字化。表示名のみMIMEデコード。
4. **Date**: `strtotime()` でUNIX秒化。失敗時はファイルmtimeで代替し `partial`。
5. **本文抽出（multipart再帰）**:
   - `Content-Type: multipart/*` は `boundary` で分割し再帰。`multipart/alternative` は **text/plain優先**、無ければtext/htmlをタグ除去（`<style>`/`<script>`ブロックごと削除→`strip_tags`→エンティティデコード）してテキスト化。
   - `Content-Transfer-Encoding`: `base64` / `quoted-printable` / `7bit` / `8bit` に対応。
   - `Content-Disposition: attachment`、またはファイル名付きの非テキストパートは本文に含めず、`attachments_json` にファイル名（RFC2231/encoded-word両対応でデコード）・MIME型・デコード後サイズを記録。**実体は保存しない**。
   - ネスト深度は5、パートあたりの展開サイズは1MBで打ち切り（`partial`）。共用サーバ保護のため。
6. **文字コード正規化**: パートの `charset` パラメータを第一候補、無ければ `mb_detect_encoding($s, ['ASCII','JIS','UTF-8','ISO-2022-JP','EUC-JP','SJIS-win'], true)`。`mb_convert_encoding` でUTF-8へ。不正シーケンスは `mb_scrub` 相当（`mb_convert_encoding` のUTF-8→UTF-8変換）で除去。最後にNULバイト・C0制御文字（改行タブ除く）を除去。
7. 出力は `{message_id, subject, from_addr, from_name, date_epoch, body_text, attachments, parse_status}` の連想配列。**この関数はDBにもfmlにも触らない純関数**とし、fixtureメールでユニットテスト可能にする。

### 3.2 `actives` ファイルとの突合

- パス: `/home/ACCOUNT/fml/spool/ml/MLNAME/actives`。**読み取り専用**（`fopen 'r'` のみ。書込・lock系関数は使わない）。
- パース: 1行ずつ、`#` 始まりと空行を無視。fml4はアドレスの後ろにオプション（`m=`, `s=1` 等）が付き得るため**最初の空白区切りトークンのみ**をアドレスとして採用し、小文字化してセットに格納。`s=skip` 等の配送停止オプションは「閲覧資格に影響しない」扱いとする（配送停止≠退会）。
- **退会者の即時遮断の保証**: activesの内容はDBにキャッシュ**しない**。以下のすべてのタイミングでファイルを直接読む:
  1. ログイン時（POST /auth/login）
  2. トークン発行時（POST /auth/request-token）
  3. トークンによるパスワード設定時（POST /auth/set-password）
  4. **認証必須APIの全リクエスト**（セッション検証の一部として毎回）
  ファイルは高々数百行・数KBであり、毎リクエスト読込のコストは無視できる。これにより「activesから削除→次のHTTPリクエストから即403（セッションも実質無効化）」が構造的に保証される。
- activesが読めない場合（fml側メンテ等）は**フェイルクローズ**: 全認証を拒否し `503 server_error` を返す（誤って全開放しない）。

### 3.3 遅延増分インデックスのアルゴリズムと排他制御

**トリガ**: `GET /api/articles`（一覧・検索）のリクエスト処理の**冒頭**で毎回試行。

```
function maybe_index():
  seq = intval(file_get_contents(SPOOL_DIR/../seq))     # fmlの最終記事番号
  max = SELECT COALESCE(MAX(id),0) FROM articles
  if seq <= max: return                                  # 差分なし（通常パス。コストはstat+SELECT1回）

  fp = fopen(locks/indexer.lock, 'c')
  if !flock(fp, LOCK_EX | LOCK_NB): return               # 他リクエストが処理中→即座に諦めて現状データで応答

  deadline = time() + 5                                  # 時間上限5秒
  batch = 0
  for n in (max+1) .. seq:
    if batch >= 50 or time() >= deadline: break          # 件数上限50通
    path = SPOOL_DIR + '/' + n                           # nは整数のみ（§7.6）
    if !is_file(path):
        INSERT (id=n, parse_status='error', ...)         # 欠番も行で埋める
    else:
        parsed = parse_mail_file(path)
        INSERT OR IGNORE articles(...)                   # 再実行安全
    batch++
    if batch % 10 == 0: COMMIT; BEGIN                    # 細かくコミットしWAL肥大と長トランザクションを回避
  COMMIT
  flock(fp, LOCK_UN); fclose(fp)
```

- **多重実行防止**: `LOCK_NB` 付き排他flock。取れなければ何もしない（待たない）ので、同時アクセスがあってもインデックス処理は常に1プロセス。ロックファイルは `mlviewer-data/locks/` に置き、fml側のファイルには一切lockをかけない。
- **503対策**: 1リクエストあたり最大50通・5秒。処理しきれない分は次のリクエストが引き継ぐ（ユーザーが画面を開いているだけで自然に追いつく）。
- **初回フルインデックス**: 同じ仕組みで賄う。管理者（＝ログイン済みメンバー）が一覧画面をリロードするたびに50通ずつ進む。加えて `GET /api/index/status`（認証必須）で `{indexed_max, seq, pending}` を返し、README記載の手順「pendingが0になるまで一覧APIを叩く」を、フロント側でも「インデックス構築中: 残りN通」バナー＋自動再フェッチとして実装する（§6.5）。数千通でも数十リクエスト＝数分で完了する。
- `seq` ファイルが読めない場合はインデックス処理をスキップするだけで、閲覧APIは既存データで正常応答する。

---

## 4. API仕様書

### 4.1 共通事項

- ベースURL: `https://<host>/ml-viewer/api`。`api/.htaccess` の mod_rewrite で全パスを `index.php` に集約し、`REQUEST_URI` からルーティング（さくらは.htaccess/mod_rewrite利用可）。万一rewrite不可でも `index.php?route=/articles` 形式で動くフォールバックを実装する。
- リクエスト/レスポンスとも `application/json; charset=utf-8`（GETのパラメータはクエリ文字列）。
- 認証: PHPセッションCookie（`Secure; HttpOnly; SameSite=Lax`、Cookie名 `MLVSESS`）。
- **CSRF**: すべてのPOSTで `X-CSRF-Token` ヘッダ必須（§5.7）。トークンは `GET /auth/session` で取得。
- 文字数上限: email 254 / password 8〜128 / q 100 / sender 254。超過は `invalid_request`。

**エラーレスポンス形式**（全エンドポイント共通）:

```json
{ "error": { "code": "invalid_credentials", "message": "メールアドレスまたはパスワードが正しくありません。" } }
```

| HTTP | code | 用途 |
|---|---|---|
| 400 | `invalid_request` | パラメータ不正・欠落 |
| 401 | `unauthorized` | 未ログイン／セッション失効 |
| 401 | `invalid_credentials` | ログイン失敗（存在有無を区別しない単一メッセージ） |
| 400 | `invalid_token` | トークン不正・期限切れ・使用済み（理由は区別しない） |
| 403 | `forbidden` | CSRF不一致（`csrf_mismatch`をdetailに含めず同一応答）／actives落ち |
| 404 | `not_found` | 記事なし・ルートなし |
| 429 | `rate_limited` | レート制限。`Retry-After` ヘッダ付与 |
| 500/503 | `server_error` | 内部エラー（詳細はログのみ。メッセージは固定文言） |

### 4.2 エンドポイント一覧

| メソッド | パス | 認証 | CSRF | 概要 |
|---|---|---|---|---|
| GET | `/auth/session` | 不要 | — | セッション状態とCSRFトークン取得 |
| POST | `/auth/login` | 不要 | 要 | ログイン |
| POST | `/auth/logout` | 要 | 要 | ログアウト |
| POST | `/auth/request-token` | 不要 | 要 | パスワード設定/リセット用トークンをメール送付 |
| POST | `/auth/set-password` | 不要 | 要 | トークンでパスワード設定 |
| POST | `/auth/change-password` | 要 | 要 | ログイン中のパスワード変更 |
| GET | `/articles` | 要 | — | 記事一覧・検索（インデックス増分処理を便乗実行） |
| GET | `/articles/{id}` | 要 | — | 記事詳細 |
| GET | `/index/status` | 要 | — | インデックス進捗 |
| GET | `/admin/users` | 要＋**管理者** | — | 会員のパスワード登録状況一覧（§4.5） |
| GET | `/admin/articles` | 要＋**管理者** | — | インデックス診断・記事一覧（error含む）（§4.5） |

### 4.3 認証系

**GET /auth/session** → 200
```json
{ "authenticated": true, "email": "user@example.com", "csrf_token": "…", "is_admin": false }
```
未ログイン時は `{"authenticated": false, "csrf_token": "…"}`（CSRFトークンは未ログインでも発行。login自体をCSRF保護するため）。
`is_admin` は改訂3で追加（判定は§5.8）。認証済み応答にのみ含め、未ログイン応答には含めない。

**POST /auth/login** — `{ "email": "…", "password": "…" }`
→ 200 `{ "email": "…", "csrf_token": "…", "is_admin": false }`（`session_regenerate_id` 後の新トークン。`is_admin` は改訂3で追加）
→ 401 `invalid_credentials` ／ 429 `rate_limited`

**POST /auth/logout** — body無し → 200 `{}`

**POST /auth/request-token** — `{ "email": "…" }`
→ **常に** 200 `{ "message": "入力されたアドレスがメンバーとして登録されている場合、案内メールを送信しました。" }`
（actives不在・送信失敗でも同一応答＝メンバー列挙防止。§5.4）
→ 429 のみ例外的に返す。

**POST /auth/set-password** — `{ "token": "…", "password": "…" }`
→ 200 `{}`（成功。既存セッションには影響しない。ユーザーは続けてログインする）
→ 400 `invalid_token` ／ 400 `invalid_request`（パスワードポリシー violation: 8文字以上128以下）

**POST /auth/change-password** — `{ "current_password": "…", "new_password": "…" }`
→ 200 `{}` ／ 401 `invalid_credentials`（現行パスワード不一致） ／ 400 `invalid_request`

### 4.4 記事系

**GET /articles** — クエリパラメータ:

| パラメータ | 型/形式 | 既定 | 説明 |
|---|---|---|---|
| `page` | int ≥1 | 1 | ページ番号 |
| `per_page` | int 1〜100 | 50 | ページサイズ |
| `q` | string | — | キーワード。空白区切りで**AND**。各語を `subject LIKE '%w%' OR body_text LIKE '%w%'`（`%`/`_`/`\` はESCAPE） |
| `sender` | string | — | 送信者。`from_addr LIKE '%s%' OR from_name LIKE '%s%'`（部分一致） |
| `date_from` | `YYYY-MM-DD` | — | この日の00:00(JST)以降 |
| `date_to` | `YYYY-MM-DD` | — | この日の23:59:59(JST)以前 |
| `id_from` | int 1〜999999999 | — | 記事番号（fml連番）の下限。`id >= :id_from` |
| `id_to` | int 1〜999999999 | — | 記事番号（fml連番）の上限。`id <= :id_to` |

- `id_from` / `id_to` は改訂2で追加。記事詳細ルートの `{id}` と同じく最大9桁の正の整数のみ受け付け、形式不正は `invalid_request`。`id_from > id_to` の場合はエラーにせず結果0件として扱う（他の範囲条件と同じ挙動）。

→ 200
```json
{
  "items": [
    { "id": 123, "subject": "…", "from_name": "…", "from_addr": "…",
      "date": "2026-07-01T12:34:56+09:00", "has_attachments": true,
      "snippet": "本文先頭120文字…" }
  ],
  "total": 1234, "page": 1, "per_page": 50,
  "indexing": { "pending": 0 }
}
```
- 並び順は `id DESC`（新着順）固定。`parse_status='error'` 行は除外。
- `total` は同条件の `COUNT(*)`。`indexing.pending` は `seq - MAX(id)`（フロントの構築中バナー用）。
- 日付はJSTで解釈しepoch範囲に変換（`DateTimeImmutable` + `Asia/Tokyo`）。

**GET /articles/{id}** — `{id}` は正規表現 `[0-9]{1,9}` のみルート一致（§7.6）
→ 200
```json
{ "id": 123, "subject": "…", "from_name": "…", "from_addr": "…",
  "date": "2026-07-01T12:34:56+09:00", "message_id": "<…>",
  "body_text": "プレーンテキスト本文全文",
  "attachments": [ { "filename": "a.pdf", "mime": "application/pdf", "size": 12345 } ],
  "parse_status": "ok" }
```
→ 404 `not_found`（`parse_status='error'` の行も404）

**GET /index/status** → 200 `{ "indexed_max": 1200, "seq": 1234, "pending": 34 }`

### 4.5 管理系（改訂3で追加）

共通事項:

- 認証必須に加え**管理者判定（§5.8）を毎リクエスト実施**。非管理者は 403 `forbidden`（一般の403と同一形式・同一文言。管理機能の存在や「管理者ではない」ことのヒントを与えない）。
- **GETのみ・読み取り専用**。変更系の管理エンドポイントは設けない（本改訂のスコープは「DBの状態確認」のみ）。
- DBスキーマの変更は不要（既存の `users` / `tokens` / `articles` と actives / seq ファイルの突合だけで構成する）。

**GET /admin/users** → 200

```json
{
  "summary": { "active_members": 25, "password_registered": 18, "orphan_users": 1 },
  "members": [
    { "email": "user@example.com",
      "password_registered": true,
      "created_at": "2026-07-01T12:00:00+09:00",
      "password_updated_at": "2026-07-02T09:00:00+09:00",
      "last_login_at": "2026-07-10T08:30:00+09:00",
      "pending_token": false }
  ],
  "orphan_users": [
    { "email": "left@example.com", "password_registered": true,
      "created_at": "…", "password_updated_at": "…", "last_login_at": "…",
      "pending_token": false }
  ]
}
```

- `members`: **actives を正とした全会員**（email昇順）。`users` テーブルに行が無い会員も載せる（`password_registered: false`、日時系はすべて `null`）。これにより「招待済みだが未登録の会員」が一覧で分かる。
- `orphan_users`: `users` テーブルに行があるが actives に不在のアドレス（退会後の残骸検知用。ログイン不可であることは既存の突合で保証済みなので、これは診断表示のみ）。
- `pending_token`: 未使用かつ期限内のトークンが存在するかの真偽値。**トークン値・トークンハッシュ・`password_hash` はいかなる形でも応答に含めない。**
- 日時はISO8601（JST）または `null`。会員数は高々数百のためページネーションは行わない。

**GET /admin/articles** — クエリパラメータ:

| パラメータ | 型/形式 | 既定 | 説明 |
|---|---|---|---|
| `page` | int ≥1 | 1 | ページ番号 |
| `per_page` | int 1〜100 | 50 | ページサイズ |
| `status` | `all` / `ok` / `partial` / `error` | `all` | `parse_status` での絞り込み |

→ 200

```json
{
  "summary": { "seq": 1234, "indexed_max": 1234, "pending": 0,
               "count_ok": 1200, "count_partial": 30, "count_error": 4 },
  "items": [
    { "id": 5, "subject": "", "from_addr": "", "date": null,
      "parse_status": "error", "indexed_at": "2026-07-01T12:00:00+09:00" }
  ],
  "total": 1234, "page": 1, "per_page": 50
}
```

- 一般の `/articles` と異なり **`parse_status='error'` の行も返す**（欠番・破損記事の診断が本画面の目的のため）。本文・添付メタは返さない（診断に不要で、応答を軽くする）。
- `date` は `date_epoch=0`（error行など）のとき `null`。並び順は `id DESC` 固定。
- `summary` の件数は `status` フィルタに依らず常に全体の値を返す（フィルタを切り替えてもサマリが安定して見えるように）。
- このエンドポイントは**インデックス便乗処理（§3.3）を実行しない**。診断時は状態を動かさずに観察できるべきで、インデックスを進めたければ通常の一覧画面を開けばよい。

---

## 5. 認証・パスワードフロー

### 5.1 ログイン

```
1. C→S: GET /auth/session            # CSRFトークン取得
2. C→S: POST /auth/login {email, password} + X-CSRF-Token
3. S: レート制限チェック（email/IP両バケット）→ 超過なら429
4. S: email正規化（trim・小文字化）
5. S: actives突合 → 不在なら password_verify をダミーハッシュで空実行し（タイミング均一化）401
6. S: users行取得。無い or password_hash NULL → 同上ダミー検証して401
7. S: password_verify → 失敗なら401（失敗カウント加算）
8. S: session_regenerate_id(true) → $_SESSION['email'] 設定、CSRFトークン再生成
9. S→C: 200 {email, csrf_token}
```

### 5.2 初回パスワード登録 ＝ パスワードリセット（同一フロー）

```
1. C→S: POST /auth/request-token {email} + X-CSRF-Token
2. S: レート制限（email: 3回/時, IP: 10回/時）→ 超過なら429
3. S: email正規化 → actives突合
   - 不在: 何もしないが、乱数生成＋ハッシュ計算は実行する（応答文言を在籍時と揃えるため）→ 6へ
   - 在籍: users行をINSERT OR IGNOREで確保
4. S: 同emailの未使用トークンを全失効 → 新トークン生成
   token = base64url(random_bytes(32))   # 平文はメールにのみ載せる
   INSERT tokens(email, sha256(token), expires_at=now+24h)
5. S: mail()送信（§5.5）。本文リンク:
   https://<host>/ml-viewer/#/set-password?token=<token>
6. S→C: 常に 200 {message: "登録されている場合、送信しました"}

7. ユーザー: メールのリンクを開く → SPAがパスワード設定画面を表示
8. C→S: POST /auth/set-password {token, password} + X-CSRF-Token
9. S: sha256(token) でtokens検索。不在／期限切れ／used_at非NULL → 400 invalid_token（理由は区別しない）
10. S: actives再突合（発行後に退会した場合を遮断）→ 不在なら400 invalid_token
11. S: パスワードポリシー検証（8〜128文字）→ password_hash してusersへUPDATE
12. S: used_at=now で消込み。**同emailの他トークンも全失効**
13. S→C: 200 → 画面はログインフォームへ誘導
```

- リセットも同じ `request-token` を使う（「パスワードをお忘れの方／初めての方」導線が1本になる）。
- パスワード設定はセッションを発行しない（メールリンク→即ログイン状態にしない）。設定後に通常ログインさせることで、トークン漏洩単体ではセッション奪取に至らない。

### 5.3 パスワード変更（ログイン中）／ログアウト

```
change-password:
1. セッション検証（§5.6の毎リクエスト検証を通過済み）
2. current_password を password_verify → 不一致401
3. new_password ポリシー検証 → UPDATE users
4. session_regenerate_id(true)（変更を機にID更新）→ 200
logout:
1. $_SESSION 全消去 → session_destroy → Cookie失効（過去日付Set-Cookie）→ 200
```

### 5.4 トークン設計とメンバー列挙防止

| 項目 | 設計 |
|---|---|
| 生成 | `random_bytes(32)` → base64url（43文字） |
| 保存 | SHA-256ハッシュのみ（DB漏洩時もトークン悪用不可） |
| 有効期限 | 24時間 |
| 再利用防止 | `used_at` 消込み＋使用時に同emailの全トークン失効。発行時も既存分を全失効（有効トークンは常に1つ） |
| 列挙防止（応答内容） | request-token・login・set-passwordのいずれも、在籍/非在籍・成功/失敗の理由によらず**応答文言を単一化**する |
| 列挙防止（処理時間） | login: 実会員検証と同じ`password_verify()`コストのダミーハッシュ（実行環境の`PASSWORD_DEFAULT`で一度だけ生成し使い回す。§7.3参照）で不在時・未設定時も検証を行い、時間差を実用上区別できない水準まで縮める。request-tokenは乱数生成・ハッシュ計算は在籍有無によらず必ず実行するが、**在籍時のみ発生するDBトランザクション・`mail()`送信の時間差は許容する**（完全な時間均一化は行わない）。この残存する時間差は§5.6のレート制限（`token:e` 3回/時、`token:ip` 10回/時）で実用上の列挙試行速度を抑える方針とし、時間ベースのサイドチャネルよりコストとのバランスを優先した設計判断とする |

### 5.5 メール送信

- `mail($to, $subject, $body, $headers, '-f'.ENVELOPE_FROM)`。
- `From:` およびエンベロープFrom（`-f`）とも `config.php` の `MAIL_FROM`（例: `ml-viewer@<自ドメイン>`、**さくらで送信するとSPFが通る自ドメインのアドレス**）に設定。MLアドレスをFromにしない（既存ML×DMARC対応と干渉させない）。
- Subject/本文はUTF-8＋`mb_encode_mimeheader`。宛先はactives由来のアドレスのみ（外部入力アドレスへ送らない）。

### 5.6 セッション管理・レート制限

- セッション: `session.save_path=~/mlviewer-data/sessions`（700）、`gc_maxlifetime=28800`（8時間）、`use_strict_mode=1`、`cookie_secure=1`、`cookie_httponly=1`、`cookie_samesite=Lax`、Cookie名 `MLVSESS`。ログイン成功・パスワード変更時に `session_regenerate_id(true)`（セッション固定攻撃対策）。
- **毎リクエスト検証**（認証必須API共通ミドルウェア）: セッションemail存在 → activesに在籍 → 通過。activesから消えていれば即 `session_destroy` して403。
- レート制限（固定ウィンドウ、`rate_limits` テーブル）:

| バケット | 制限 | 超過時 |
|---|---|---|
| `login:e:<email>` | 5失敗 / 15分 | 429（成功でリセット） |
| `login:ip:<ip>` | 20失敗 / 15分 | 429 |
| `token:e:<email>` | 3回 / 60分 | 429 |
| `token:ip:<ip>` | 10回 / 60分 | 429 |
| `setpw:ip:<ip>` | 10回 / 15分 | 429（トークン総当たり対策） |

IPは `$_SERVER['REMOTE_ADDR']` のみ使用（`X-Forwarded-For` は信用しない）。

### 5.7 CSRF対策

- セッション開始時に `random_bytes(32)` のトークンを `$_SESSION['csrf']` に保持。`GET /auth/session` がJSONで返し、フロントは全POSTに `X-CSRF-Token` ヘッダで添付。検証は `hash_equals`。不一致は403（固定文言）。
- Cookieが `SameSite=Lax` である上での**二重防御**。加えて `Origin` ヘッダが存在する場合は自オリジンと一致することを検証する（存在しない場合はCSRFトークンのみで判定）。
- カスタムヘッダ必須のため、フォーム直POST型CSRFは構造的に不成立。

### 5.8 管理者判定（adminsファイル。改訂3で追加）

管理者の正は、activesと同様の**プレーンテキストファイル**とする（DBに管理者フラグを持たない）。

| 項目 | 設計 |
|---|---|
| ファイル | 既定 `~/mlviewer-data/admins`。パスは `config.php` の `admins_file` キーで指定 |
| 形式 | activesと同一規約: 1行1メールアドレス、`#` 始まりと空行は無視、行内の**最初の空白区切りトークンのみ**採用、小文字化。パーサは `actives.php` の実装を流用する |
| 判定式 | **管理者 ＝ 認証済み ∧ actives在籍 ∧ adminsに記載**。activesを外れた者はadminsに残っていても管理者ではない（退会者即時遮断の原則§3.2をそのまま適用） |
| 読み取りタイミング | `is_admin` を返す `GET /auth/session` と、管理系API（§4.5）の**全リクエストで毎回ファイルを直接読む**。DB・セッションにキャッシュしない → FTPでファイルを差し替えれば次のリクエストから反映 |
| 不在時の挙動 | ファイルが存在しない／`admins_file` キーが未設定／読み取り不能、のいずれも**管理者ゼロ**として扱う（全員 `is_admin: false`、管理系APIは403）。activesと違い503にしない: adminsは任意機能であり「無い」が正常状態のため、フェイルクローズの向きが逆になる |
| 互換性 | `config.php` に `admins_file` キーが無い既存デプロイ環境でも動作を変えない（＝管理機能が無効なだけ）。既存環境のconfig.php更新は任意 |
| 配置上の注意 | 必ずWeb非公開領域（`mlviewer-data/` 推奨、パーミッション600）に置く。公開領域に置くと管理者アドレス一覧が誰でもダウンロード可能になる。READMEの運用チェックリストに含める |

- adminsファイルの**編集ミスで壊れるのは管理機能だけ**であり、アプリ本体には波及しない（config.phpに管理者リストを書く方式を採らない理由。PHP構文エラーは全機能を落とす）。
- 管理機能は読み取り専用のため、管理者追加によって増える権限は「DB状態の閲覧」のみ（§7.7）。

> 改訂2の変更概要: 3ペイン構成（上部バー＋左一覧＋右詳細）を廃止し、**コントロールパネル＋記事カード一覧の1カラム構成**に変更する。記事詳細は右ペインではなく**カードのインライン展開**で表示する。検索は常時表示のフィルタ入力からダイアログに集約し、記事番号範囲の条件を追加する。これによりモバイル表示にも自然に対応する。

### 6.1 画面・ルーティング

SPA1枚（`~/www/ml-viewer/index.html`）。**ハッシュルーティング**（`#/…`）を採用し、サーバ側のSPA用rewriteを不要にする（.htaccessはAPIとHTTPS強制のみに専念できる）。ルートは少ないのでルーターライブラリは入れず、`window.location.hash` を読む小さな `useHashRoute` フックで済ませる。

| ハッシュ | 画面 | 認証 |
|---|---|---|
| `#/`（既定） | メイン（コントロールパネル＋記事カード一覧） | 要（未ログインならログイン画面を表示） |
| `#/set-password?token=…` | パスワード設定（初回/リセット共用） | 不要 |
| `#/admin/users` | ユーザー管理（§6.9） | 要＋管理者（非管理者は `#/` へ戻す） |
| `#/admin/articles` | 記事管理（§6.9） | 要＋管理者（非管理者は `#/` へ戻す） |
| （画面内Dialog） | 検索ダイアログ／パスワード変更ダイアログ | 要 |

### 6.2 メイン画面のレイアウトと挙動

```
┌─────────────────────────────────────────────┐
│ [ロゴ/タイトル]   [投稿絞り込み] [ユーザーメニュー] │ ← ControlPanel（上部固定）
│ （絞り込み中のみ）[条件チップ…] [絞り込み解除]      │
├─────────────────────────────────────────────┤
│ （インデックス構築中のみ）進捗バナー               │
├─────────────────────────────────────────────┤
│ ┌─────────────────────────────────────┐     │
│ │ 件名 / 送信者 / 日付 / 添付 / 冒頭抜粋 │     │ ← ArticleCard（折りたたみ状態）
│ └─────────────────────────────────────┘     │
│ ┌─────────────────────────────────────┐     │
│ │ 件名 / 送信者 / 日付 / 添付        ∧ │     │ ← ArticleCard（展開状態）
│ │ ───────────────────────────────────  │     │
│ │ 本文全文（プレーンテキスト＋URLリンク）│     │
│ │ 添付ファイル一覧(Badge)               │     │
│ │                          [閉じる]     │     │
│ └─────────────────────────────────────┘     │
│                 […さらに読み込む]             │
└─────────────────────────────────────────────┘
```

**コントロールパネル**

- 左上に**ブランディング表示**（§6.6）。
- 「**投稿絞り込み**」ボタンで検索ダイアログ（§6.3）を開く。
- 絞り込み適用中は、コントロールパネル内に**条件チップ**（`Badge`。例: `記事番号 100〜200` `送信者: 山田` `キーワード: 会議` `2026-07-01〜2026-07-31`）と「**絞り込み解除**」ボタンを表示する。チップは指定された条件のみ表示。解除ボタンで全条件をクリアし全件表示に戻る。
- 右上に既存のユーザーメニュー（ログイン中email表示・パスワード変更・ログアウト）。

**記事カード一覧**

- 記事は新着順（`id DESC`）のカードとして1カラムで縦に並ぶ。カードの情報量は旧左ペインのリスト項目と同等（件名・送信者・日付・添付アイコン・本文冒頭の抜粋）。
- ページングは従来どおり「さらに読み込む」ボタンのページ追記型（50件単位）。

**カードのインライン展開（旧右ペインの代替）**

- カードをクリックすると、その場でカードが縦に展開され、記事全文（旧右ペイン相当: 件名・送信者・日付・本文全文・添付一覧・parse_status警告）を表示する。展開時に `GET /articles/{id}` を取得する（一覧APIは全文を返さないため）。
- **複数カードの同時展開を許可する**（排他にしない）。読み比べができることがインライン方式の利点であるため。展開状態は画面内のローカルstate（展開中ID集合）で管理する。
- **開いていることの視覚表現**: 展開中カードは枠線・背景色を強調し、右上のシェブロンアイコン（折りたたみ時 ∨ / 展開時 ∧）で状態を示す。
- **閉じる導線は上下2箇所**: (1) カードヘッダー（件名部分）の再クリック、(2) 本文末尾の「閉じる」ボタン。長文を読み終えた位置からスクロールせずに閉じられるようにする。
- **閉じたときのスクロール補正**: 展開中に大きくスクロールした状態で閉じると閲覧位置が飛ぶため、閉じたカードの先頭が画面内に入るよう `scrollIntoView` 相当の補正を行う。

### 6.3 検索ダイアログ

「投稿絞り込み」ボタンで開く `Dialog`。以下の4条件を任意の組み合わせで指定し、「絞り込み」ボタンで適用する（＝APIのクエリパラメータに反映して一覧を再取得）。

| 条件 | 入力UI | 対応APIパラメータ |
|---|---|---|
| 記事番号の範囲 | 数値Input×2（から/まで。片側のみ指定可） | `id_from` / `id_to` |
| 投稿者名 | Input（アドレス・表示名の部分一致） | `sender` |
| キーワード | Input（空白区切りAND。**件名＋本文**を対象とする） | `q` |
| 日付範囲 | `Calendar`（range選択。Popoverではなくダイアログ内に直接配置） | `date_from` / `date_to` |

- 適用は「絞り込み」ボタン押下時のみ（旧設計の300msデバウンス即時検索は廃止。ダイアログ方式では明示的な適用の方が自然なため）。
- ダイアログを再度開いたとき、現在適用中の条件が入力欄に復元されている（追加・修正して再適用できる）。
- 「クリア」ボタンをダイアログ内にも置き、全欄を空にできる。
- バリデーション: 記事番号は1〜999999999の整数のみ。`id_from > id_to` はエラーにせずそのまま送る（API側で0件になる。§4.4）。

### 6.4 コンポーネント分割

```
App
├── SessionProvider(Context)          # GET /auth/session の結果とCSRFトークンを保持
├── LoginPage                         # email/password + 「初めての方・お忘れの方」リンク
│   └── RequestTokenForm              # request-token 送信フォーム（同画面内切替）
├── SetPasswordPage                   # token付きリンクから遷移
└── MainLayout                        # 認証済みのみ。上部固定バー + ハッシュで切り替わる本文領域（§6.1/§6.9）
    ├── ControlPanel                  # 上部バー（全ルート共通）
    │   ├── Branding                  # ロゴ or タイトル文字列（§6.6）
    │   ├── FilterChips               # 適用中の条件チップ＋絞り込み解除ボタン（メイン画面かつ適用中のみ）
    │   ├── SearchDialog              # 「投稿絞り込み」ボタンと検索ダイアログ（メイン画面のみ。§6.3）
    │   ├── AdminMenu(DropdownMenu)   # is_adminのときのみ「管理機能」→ユーザー管理/記事管理（§6.9）
    │   ├── UserMenu(DropdownMenu)    # ログイン中email表示・パスワード変更・ログアウト
    │   └── ChangePasswordDialog
    ├── IndexingBanner                # メイン画面のみ。pending>0 のとき進捗表示＋自動再フェッチ
    ├── AdminUsersPage (#/admin/users)      # §6.9
    ├── AdminArticlesPage (#/admin/articles) # §6.9
    └── ArticleCardList (#/)          # カード一覧＋「さらに読み込む」
        └── ArticleCard               # 折りたたみ/展開の2状態を持つ単一コンポーネント(サブコンポーネントに分割しない)
            # 折りたたみ時: 件名/送信者/日付/添付アイコン/シェブロン(クリックでトグル)/本文冒頭抜粋(snippet)
            # 展開時: GET /articles/{id} を取得し、送信者フル表記(name<addr>)・時刻付き日時、
            #         ArticleHeader(添付一覧Badge・parse_status警告、既存を流用)、
            #         ArticleBody(プレーンテキスト+URL自動リンク、既存を流用。§7.2)、
            #         末尾に「閉じる」ボタンを表示
```

旧 `ArticleList` / `ArticleListItem` / `ArticleDetail` / `SearchFilters` は廃止し、上記に置き換える。`ArticleHeader` / `ArticleBody`（XSS対策済みの本文描画）はそのまま流用する。`ArticleCard`は折りたたみ/展開の表示切り替えを内部の条件分岐で行い、ファイル分割はしない（実装量に対してファイル分割の効果が薄いため）。

### 6.5 使用するshadcn/uiコンポーネント

`Button` `Input` `Label` `Card`（記事カード） `Dialog`（検索・パスワード変更） `DropdownMenu` `Calendar`（日付範囲。検索ダイアログ内に直接配置） `Skeleton`（ローディング） `Badge`（添付・条件チップ・parse_status表示） `Alert`（エラー・空状態） `Separator` `Sonner`（トースト通知） `Tooltip` `Table`（管理画面の一覧。改訂3で追加） `Select`（記事管理のstatusフィルタ。改訂3で追加）。
`Popover` は日付選択がダイアログ内配置になったため必須ではなくなる（残置してよい）。`ScrollArea` はペイン内スクロールが無くなりページ全体のスクロールになるため不要（残置してよい）。

### 6.6 ブランディング（ヘッダー左上のロゴ/タイトル）

fmlを運用するML毎に見た目を変えられるよう、**ビルド・設定変更なしで差し替え可能な固定ファイル名規約**を採用する。公開ディレクトリ直下の `branding/` を参照し、以下の優先順位でフォールバックする:

| 優先 | ファイル | 挙動 |
|---|---|---|
| 1 | `~/www/ml-viewer/branding/logo.png` | 存在すれば（fetchが200なら）**ロゴ画像のみ**をヘッダー左上に表示 |
| 2 | `~/www/ml-viewer/branding/title.txt` | logo.pngが無ければ、このファイルの**1行目**をタイトル文字列として表示 |
| 3 | （どちらも無い） | 既定文字列「**ML Viewer**」を表示 |

- フロントは起動時に上記を順にfetchし、404は正常系として次へフォールバックする（エラー表示・コンソールエラーを出さない）。
- ブラウザタブのタイトル（`document.title`）とロゴの`alt`属性には、`title.txt` があればその1行目、無ければ「ML Viewer」を使う（ロゴ表示時もタブには文字が必要なため。ロゴ＋タブ名を両立したい場合は両ファイルを置く運用）。
- ロゴはヘッダー高さに収まるようCSSで高さ上限（32px程度）を設け、横長ロゴを想定する。
- `branding/` はリポジトリに含めない（運用者がFTPで直接設置する）。デプロイ手順書（README）に設置方法を記載する。バックエンド・`config.php`への変更は不要。
- `title.txt` の内容はテキストノードとして描画する（HTMLとして解釈しない。§7.2のXSS方針と同じ）。

### 6.7 状態管理・データ取得

- **TanStack Query（react-query）を採用**。理由: 一覧のページング・検索条件をクエリキーにした宣言的キャッシュ、展開時の記事詳細の記事ID単位キャッシュ（同じカードの開閉で再取得しない）、IndexingBannerの `refetchInterval` 制御が自前実装より簡潔・堅牢になるため。追加ライブラリはこれ1つ。
- 検索条件（適用済みのもの）と展開中カードのID集合は `MainLayout` のローカルstateで管理。認証状態のみ軽量Context（SessionProvider）。グローバル状態ライブラリは不要。
- API層 `src/api/client.ts`: `fetch` ラッパ（`credentials: 'same-origin'`、POST時にCSRFヘッダ自動付与、401受信でセッションContextを未認証化→ログイン画面へ）。`src/api/types.ts` にAPIレスポンスの型定義（§4のJSONスキーマと1:1対応。`id_from`/`id_to` を追加）。
- 検索の適用はダイアログの「絞り込み」ボタン押下時のみ（デバウンス即時検索は廃止。§6.3）。

### 6.8 ローディング・エラー・空状態

| 状況 | UI |
|---|---|
| 一覧初回ロード | カード形の `Skeleton` ×10 |
| カード展開時の全文ロード | 展開領域内に `Skeleton` ブロック |
| 検索結果0件 | 「条件に一致する記事がありません」＋絞り込み解除ボタン |
| インデックス構築中 | 上部に `Alert`「記事インデックスを構築中（残りN通）」＋pending>0の間は5秒間隔で一覧を自動再フェッチ |
| APIエラー | `Sonner` トースト＋一覧/展開領域にリトライボタン付き `Alert` |
| 401/403 | セッション切れとしてログイン画面へ（適用中の検索条件はメモリ保持） |
| 429 | 「試行回数が多すぎます。しばらくしてからお試しください」固定文言 |
| branding取得の404 | エラー扱いしない（§6.6のフォールバック） |

### 6.9 管理画面（改訂3で追加）

**導線と表示条件**

- `GET /auth/session` の `is_admin` が true のときのみ、ControlPanel（UserMenuの左隣）に「**管理機能**」ボタン（`DropdownMenu`）を表示する。メニュー項目は「**ユーザー管理**」（`#/admin/users`）と「**記事管理**」（`#/admin/articles`）。
- 非管理者が `#/admin/*` をURL直打ちした場合、フロントは `#/` に戻す。仮にAPIを直叩きしても403になる（判定はサーバー側§5.8が本体で、ボタン非表示・ルート制御はUI上の便宜にすぎない）。
- 管理画面表示中は、絞り込み関連UI（投稿絞り込みボタン・条件チップ）と `IndexingBanner` を表示しない。代わりにコンテンツ領域の先頭に画面タイトル（「ユーザー管理」「記事管理」）と「**← 記事一覧へ戻る**」導線を置く。

**ユーザー管理（AdminUsersPage）** — `GET /admin/users` を表示

- 上部にサマリ: 会員数／パスワード登録済み数／残骸ユーザー数。
- 会員一覧（`Table`）: メールアドレス／パスワード登録状況（登録済み・未登録の `Badge`）／登録日時／最終ログイン日時／発行中トークン有無。日時 `null` は「—」表示。
- `orphan_users` が1件以上あるときだけ、警告色の見出し付きセクション「activesに存在しないユーザー」として別表に表示する。これはfml側運用やDB手作業の判断材料であり、**本画面から削除等の操作は提供しない**（読み取り専用の原則）。

**記事管理（AdminArticlesPage）** — `GET /admin/articles` を表示

- 上部にサマリ: `seq`（fml最終記事番号）／`indexed_max`／未処理 `pending`／parse_status別件数（ok・partial・error）。`pending > 0` と `error > 0` は警告色で強調（「データ化が正しく動いているか」の一次判定がここで完結する）。
- parse_statusフィルタ（`Select`: すべて／ok／partial／error）付きの記事一覧（`Table`）: 記事番号／件名／送信者／日付／parse_status（`Badge`。errorは警告色）／インデックス登録日時。
- ページングは既存一覧と同じ「さらに読み込む」ボタンのページ追記型（50件単位）。フィルタ変更時は1ページ目から取り直す。
- この画面はインデックスを進行させない（§4.5。観察専用）。

**状態管理**

- TanStack Queryのクエリキーは `['admin-users']` / `['admin-articles', status, page]`。管理画面を離れたら特別な後始末は不要（通常のキャッシュ破棄に任せる）。
- `is_admin` は SessionProvider が保持する（`/auth/session` 応答に含まれるため追加リクエスト不要）。

---

## 7. セキュリティ設計まとめ

### 7.1 SQLインジェクション

- DBアクセスは `db.php` のPDOラッパ経由に一本化し、**全クエリをプリペアドステートメント＋バインド**で実行（文字列連結でSQLを組む箇所をゼロにする。動的なのはWHERE句の**構造**のみで、値はすべてプレースホルダ）。
- LIKE検索語は `%` `_` `\` を `\` エスケープしたうえでバインドし、`ESCAPE '\'` を明示。
- `PDO::ATTR_EMULATE_PREPARES = false`、`ATTR_ERRMODE = EXCEPTION`。

### 7.2 XSS

- APIは `application/json` のみ返し、`X-Content-Type-Options: nosniff` を全応答に付与。HTMLを返すエンドポイントは存在しない。
- 記事本文は**サーバ側でプレーンテキスト化済み**（HTMLメールもタグ除去済み）。フロントは `dangerouslySetInnerHTML` を**全面禁止**し、本文・件名・送信者名はすべてReactのテキストノードとして描画（自動エスケープ）。
- **URL自動リンク化はクライアント側で**実施: 本文文字列を正規表現で `https?://` のみ抽出→文字列を分割し、URL部分だけ `<a href={url} rel="noopener noreferrer" target="_blank">` のReact要素として構築する（`href` に入るのは `https?:` で始まることを検証済みの文字列のみ。`javascript:` 等は構造的に混入不可）。
- Viteビルドのindex.htmlに `<meta http-equiv="Content-Security-Policy" content="default-src 'self'">` 相当を検討するが、CSP本体は `.htaccess` の `Header set` で付与（`default-src 'self'; frame-ancestors 'none'`。※さくらでHeaderディレクティブが使えない場合はmetaタグ版のみ）。

### 7.3 認証・セッション（§5の要点再掲）

- `password_hash(PASSWORD_DEFAULT)` / `password_verify` / ダミー検証によるタイミング均一化。ダミーハッシュは固定文字列で埋め込まない（実行環境のPHPバージョンによって`PASSWORD_DEFAULT`のコストが異なり、固定コストのダミーだと逆に時間差の手がかりになるため）。実行環境で一度だけ`password_hash()`し、`mlviewer-data/`配下に保存して使い回す（毎回生成すると生成コスト自体が`password_verify()`超の負荷になり、今度はダミー側が実会員側より遅くなるため）。
- `session_regenerate_id(true)`（ログイン・パスワード変更時）。ログイン成功時はセッションIDに加えCSRFトークンも明示的に再生成する（`session_regenerate_id()`は`$_SESSION`の内容を引き継ぐため、CSRF値自体は自動更新されない）。`use_strict_mode`、専用save_path(700)。
- トークンはハッシュ保存・24h期限・単一有効・使用時全失効。
- 全POSTにCSRFトークン（カスタムヘッダ）＋Origin検証＋SameSite=Lax。
- レート制限はDBベース（§5.6）で、CGIモード（プロセス毎メモリ）でも共有される。
- request-tokenの列挙防止は応答文言の統一とレート制限が中心で、処理時間の完全な均一化は行わない（§5.4参照）。

### 7.4 パストラバーサル・入力検証

- 記事ファイルパスは `SPOOL_DIR . '/' . $id` の形でのみ生成し、`$id` はルータの正規表現 `[0-9]{1,9}` とPHP側 `(int)` キャストの二重検証。文字列がパスに混入する経路なし。
- ML名・スプールパスは `config.php` の定数のみ（外部入力から構成しない）。
- 全APIパラメータに型・長さ・形式の検証（§4.1）。未知パラメータは無視。

### 7.5 ファイル配置・パーミッション（README化する運用チェックリストの根拠）

| パス | パーミッション | 補足 |
|---|---|---|
| `~/mlviewer-data/` | 700 | docルート外。Webから到達不能 |
| `~/mlviewer-data/mlviewer.sqlite` | 600 | WAL/SHMも同等（PHP作成時にumask制御） |
| `~/mlviewer-data/sessions/` | 700 | 共有/tmp回避 |
| `~/mlviewer-app/` | 705（ファイル604） | CGI実行ユーザー=所有者なので実質所有者のみ。`config.php` は600 |
| `~/www/ml-viewer/` | 通常 | 静的物とエントリPHPのみ。DB・ログ・設定は置かない |

- さくらライトプランのPHP CGIは所有者権限で動くため、600/700で自己完結する。
- `.htaccess`（`~/www/ml-viewer/`）: HTTPS強制リダイレクト、`X-Content-Type-Options` 等のヘッダ付与、`api/` 以外への `.php` 配置はそもそも無し。

### 7.6 その他

- エラー詳細（スタックトレース・SQL・パス）はレスポンスに含めず、`~/mlviewer-data/app.log`（600）へ。`display_errors=0` を実行時に強制。
- fml側ファイルは全コードパスで読み取り専用関数のみ使用（書込み・flock対象は `mlviewer-data` 配下に限定）。
- ログにパスワード・トークン平文を書かない。

### 7.7 管理機能（改訂3で追加）

- **権限の境界はサーバー側**: 管理系APIは全リクエストで `mlv_require_admin()`（認証済み＋actives在籍＋admins記載。§5.8）を通す。フロントのボタン表示・ルート制御は境界ではない。
- **読み取り専用**: 管理系はGETのみで、変更系エンドポイントが存在しない。管理者アカウントが侵害されても、この機能経由で可能なのはDB状態の閲覧まで。
- **秘密情報の非露出**: `password_hash`・トークン値・トークンハッシュは管理APIのいかなる応答にも含めない（登録有無・トークン有無の真偽値と日時のみ）。
- **存在の秘匿**: 非管理者への403は一般の403と同一形式・同一文言（管理機能の存在や管理者判定の結果を応答から推測させない）。
- **adminsファイルの配置**: Web非公開領域（600）必須。§5.8・READMEチェックリスト参照。

---

## Phase 2への引き継ぎ事項

1. 実装は本書§1の構成・§2のスキーマ・§4のAPI仕様に**厳密に**従う。逸脱が必要になったら実装前にユーザーへ理由を提示して確認を取る。
2. `mail_parser.php` は純関数として実装し、`backend/tests/fixtures/` に「ISO-2022-JP件名」「multipart/alternative」「base64本文」「添付付き」「壊れたメール」の最低5 fixtureを置いてユニットテストを書く。
3. README.mdにはFTP配置表（§1.2/§1.3）、パーミッション表（§7.5）、初回フルインデックス手順（§3.3）、手動確認チェックリスト（ログイン、リセット、退会者遮断、検索3種、429確認）を含める。

## 改訂2の実装作業（引き継ぎ事項）

初版の実装（Phase 2）は完了・デプロイ済み。改訂2で発生する実装作業は以下のとおり。

**バックエンド（小規模）**

1. `api.php` の `mlv_build_article_where()` に `id_from` / `id_to` 条件を追加（§4.4。1〜999999999の整数検証、プレースホルダでバインド）。
2. 対応するユニットテストを `api_test.php` に追加。

**フロントエンド（中規模）**

3. `SearchFilters`（常時表示フィルタ）を廃止し、`SearchDialog`（§6.3）と `FilterChips` に置き換える。
4. `ArticleList` / `ArticleListItem` / `ArticleDetail` を廃止し、`ArticleCardList` / `ArticleCard`（インライン展開。§6.2・§6.4）に置き換える。`ArticleHeader` / `ArticleBody` は流用。
5. `Branding` コンポーネントを新設（§6.6のフォールバック規約、`document.title` 反映）。
6. `MainLayout` を1カラム構成に変更。展開中カードID集合のstate管理・スクロール補正を実装。
7. `src/api/types.ts` の `ArticleSearchParams` に `id_from` / `id_to` を追加。

**ドキュメント**

8. README.mdに `branding/` の設置方法（§6.6）を追記。手動確認チェックリストの検索項目を4条件（記事番号範囲・投稿者・キーワード・日付範囲）に更新。

## 改訂3の実装作業（引き継ぎ事項）

改訂2の実装は完了済み。改訂3（管理者機能）で発生する実装作業は以下のとおり。DBマイグレーションは不要。

**バックエンド**

1. `config.sample.php` に `admins_file` キーを追加（例: `/home/ACCOUNT/mlviewer-data/admins`）。キー欠如・ファイル不在は「管理者ゼロ」として扱い、既存デプロイ環境の動作を変えない（§5.8）。
2. `actives.php` のパーサを流用した admins 読み取りと、`mlv_is_admin(string $email): bool` ／ `mlv_require_admin(): void`（認証＋actives＋admins の3条件、失敗時403）を追加（§5.8）。
3. `GET /auth/session` の認証済み応答に `is_admin` を追加（§4.3）。
4. `admin.php`（新規）: `GET /admin/users`・`GET /admin/articles` を実装し、`router.php` に登録（§4.5）。`mlv_maybe_index()` は呼ばない。
5. ユニットテスト: adminsファイルのパース（コメント行・空行・オプション付き行・大文字混在）、members/orphans突合ロジック、admin記事一覧の status別WHERE構築（error行を含むこと）、summary件数の算出。

**フロントエンド**

6. `SessionContext` と `src/api/types.ts` に `is_admin` を追加。
7. ハッシュルーティングを `#/admin/users` / `#/admin/articles` に対応させ、`MainLayout` の本文領域をルートで切り替える（§6.4）。非管理者の直打ちは `#/` へ戻す。
8. `AdminMenu`（「管理機能」DropdownMenu）・`AdminUsersPage`・`AdminArticlesPage` を新設（§6.9）。shadcn/ui の `Table`・`Select` を追加導入。
9. 管理画面表示中は絞り込みUI・IndexingBannerを非表示にし、画面タイトルと「記事一覧へ戻る」導線を表示（§6.9）。

**ドキュメント**

10. README.md: adminsファイルの設置手順（場所・形式・パーミッション600・FTP差し替えで即反映）、手動確認チェックリスト（管理者にのみボタンが出る／非管理者のAPI直叩きが403／activesから外した管理者が権限を失う／error記事が記事管理に表示される）を追記。
