# UIR-04 Base 部品の刷新

**目的:** `components/ui/` の共通部品を新しいデザインに合わせ、各画面が部品の差し替えだけで新しい見た目に近づくようにする。

**依存:** UIR-01, UIR-02

## 作業

部品の props とイベントは変えない（呼び出し側の変更を各画面チケットに閉じ込めるため）。必要な場合だけ props を追加する。

| 部品 | 変更内容 |
| --- | --- |
| `BaseButton` | 角丸 5px（`sm` は 4px）。`primary` はグラファイトのグラデーションに上端のハイライト、`secondary` は白地＋薄い枠＋小さい影、`ghost` は枠と影なし、`danger`／`danger-ghost` はエラー色。高さは `md` 32px、`sm` 26px |
| `BaseBadge` | 角丸 4px。`brand` は墨地に白文字（「最新」など）、`neutral` は墨 6% の地。`success`／`warning`／`danger` の淡い地は残す |
| `BaseCard` | 角丸 6px、罫線 `--color-line`、標準の影、`overflow: hidden`。見出し部は下に弱い罫線、見出し 13.5px・600、説明 11.5px の弱い文字 |
| `BaseInput`／`BaseSelect`／`BaseTextarea` | 高さ 32px、角丸 5px、枠 `--color-line-strong`、内側にごく薄い影。エラー時は枠をエラー色にし、外側に 3px の淡いリング。ラベルは 11px・500 |
| `BaseToggle` | 丸いスイッチ（オン時は墨の地に白いつまみ） |
| `BaseAlert` | 角丸 6px、淡い地＋薄い枠。アイコンと見出しはその色調の文字色 |
| `BaseToasts` | 墨の地（`#161b20`）、角丸 6px、大きい影。成功時はランプの正常色の点を添える |
| `BaseModal`／`ConfirmModal` | 角丸 8px、大きい影。背景の暗幕は墨の半透明 |
| `BasePagination` | 番号ボタンは角丸 4px、現在のページは墨の地に白文字 |
| `BaseEmpty` | アイコンと文字を弱い文字色に。余白を新しいカードに合わせる |
| `FileDropzone` | 角丸 6px、1.5px の点線枠、補助面の地 |
| `MemoField` | 角丸 5px の点線枠、先頭に編集アイコン |
| `AppIcon` | 変更なし（サイズの既定値だけ確認） |

## 対象ファイル

- `src/resources/js/components/ui/*.vue`

## 完了条件

- 上の表の部品が新しいトークン（色・角丸・影）だけで色を指定しており、`slate-*`／`blue` 系の直接指定が残っていない（動作に必要な一時的なものを除く）。
- props とイベントの互換性が保たれ、既存テストが通る。
- `npm run check` と `npm run build` が通る。

## 確認コマンド

```sh
cd src
grep -nE "slate-|blue-|indigo-|sky-" resources/js/components/ui/*.vue || echo "直接指定なし"
npm run check
npm run build
```

**実装状況（2026-10-16）:** 表の部品をトークン指定に置き換えた。props とイベントは変えていない。追加したのは `MemoField` の `framed`（品番メモ用の点線枠）だけ。`BaseAlert` と `BaseToasts` のアイコンを `check_circle`／`error` に変えた。補助トークン（`ok-soft`、`warn-soft`、`graphite-edge`、`shadow-graphite`、`shadow-field`）を `app.css` に足した。`FileDropzone` は現在どの画面からも使われていないが、表のとおり見た目だけ揃えた。
