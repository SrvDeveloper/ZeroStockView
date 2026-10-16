# UIR-02 書体の自前配信

**目的:** IBM Plex Sans JP・IBM Plex Mono・Barlow Condensed を、パッケージを追加せずに自前配信する。端末によって和文の見た目が変わる状態をなくす。

**依存:** なし

## 作業

- `src/vite.config.js` の `laravel()` に `fonts` オプションを追加する。laravel-vite-plugin に標準で入っている `google()` プロバイダを使い、ビルド時にフォントを取得して自前配信する。
  ```js
  import laravel, { google } from "laravel-vite-plugin"; // 取り込み元は実装時に型定義で確認する
  laravel({
      input: [...],
      refresh: true,
      fonts: [
          google("IBM Plex Sans JP", { weights: [400, 500, 600, 700], preload: false }),
          google("IBM Plex Mono", { weights: [400, 500, 600] }),
          google("Barlow Condensed", { weights: [500, 600, 700] }),
      ],
  });
  ```
  - 和文フォントはファイル数が多いため、`preload: false` にする。
- `app.blade.php` の既存の `@fonts` で読み込まれることを確認する。
- `app.css` の `@theme` で書体トークンを定義する。
  - `--font-sans`: IBM Plex Sans JP（代替: `"Hiragino Sans", "Yu Gothic", sans-serif`）
  - `--font-mono`: IBM Plex Mono
  - `--font-num`: Barlow Condensed（代替: `"Arial Narrow", sans-serif`）
- `Instrument Sans` の指定を削除する。
- Material Symbols（`app.blade.php` の Google Fonts 読込）は今回は変更しない。

## 対象ファイル

- `src/vite.config.js`
- `src/resources/css/app.css`
- `src/resources/views/app.blade.php`（変更が必要な場合のみ）

## 注意

- `google()` はビルド時に Google Fonts へ接続する。本番のビルド環境から外部へ接続できない場合は、手元でビルドした成果物を配置するか、`local()` プロバイダで同梱する方式に切り替える。切り替える場合は、フォントファイルのライセンス表記の扱いもあわせて相談する。

## 完了条件

- `npm run build` の成果物にフォントファイルと `@font-face` が含まれ、`@fonts` がそれを参照する。
- `Instrument Sans` への参照が残っていない。
- `npm run check` が通る。

## 確認コマンド

```sh
cd src
npm run build
ls public/build/assets | grep -iE "woff2|plex|barlow"
grep -rn "Instrument Sans" resources || echo "参照なし"
npm run check
```

**実装状況（2026-10-16）:** `vite.config.js` に `google()` で 3 書体を設定し、`app.css` の `--font-sans`／`--font-mono`／`--font-num` を差し替えた。和文は 400／500／600 の 3 段階に絞った（`font-bold` の使用は 1 か所で、700 は合成で足りるため）。`fontaine` の追加を避けるため `optimizedFallbacks: false` にした。ビルドで woff2 が約 360 個生成される。`@fonts` は `@font-face` を `<style>` で HTML に埋め込む方式で、埋め込み量は約 320KB（gzip 後 約 94KB）。社内ツールとして許容と判断したが、初回表示が気になる場合は和文の太さをさらに絞る。`resources/views/welcome.blade.php` に残る `Instrument Sans` は、ルートから使われていない Laravel 既定のページなので対象外とした。

**変更（2026-10-16）:** 依頼者の指定により、和文フォントを IBM Plex Sans JP から Noto Sans JP（400／500／600）に替え、`body` に `font-feature-settings: "palt" 1` をかけた。HTML への埋め込み量はほぼ同じ（約 330KB、gzip 後 約 95KB）。等幅（IBM Plex Mono）と数字（Barlow Condensed）は変えていない。
