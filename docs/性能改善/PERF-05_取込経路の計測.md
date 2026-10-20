# PERF-05 取込経路の計測

計測日: 2026-10-20。PERF-06〜08 の変更前の値として使う。コードの動作は変えていない。

## 計測の方法

- 計測用のテスト `src/tests/Feature/ImportPathBenchmarkTest.php` で測る。通常のテストでは飛ばし、次のコマンドのときだけ動く。

  ```bash
  cd src
  RUN_BENCHMARKS=1 php artisan test tests/Feature/ImportPathBenchmarkTest.php
  # 結果を Markdown の表としてファイルにも書くとき
  RUN_BENCHMARKS=1 BENCHMARK_OUTPUT=/tmp/bench.md php artisan test tests/Feature/ImportPathBenchmarkTest.php
  ```

- データは本番と同じ規模にした。品番112件、SKU 817件（ASINあり559件）、品番グループ3つ（50・50・14品番、一部重複）。取込ファイルは実ファイルと同じ見出し・同程度の行数（倉庫毎の在庫数レポート約3,000行、KEEP一覧表約420行、在庫一覧照会表約3,500行、Shift_JIS）で、対象外の行も含む。
- DB はテスト用のインメモリ SQLite。設定先の DB には触れていない。CrossWalker と Amazon のレポートは偽の応答を返す（外部への通信時間は含まない）。
- 経路は1日の流れの順に実行する: 品番グループの保存 → 9:00 の Amazon 在庫取込（その日の調査を作る）→ 3 ファイルの取込 → 1 ファイルの追加取込 → 再照合 → Amazon 在庫の取り直し → 翌日の 3 ファイルの取込（Amazon 在庫の取込より先）。各経路が反映まで済んだことをテストの中で確かめている。
- 「トランザクション」はアプリが開いたトランザクションの合計時間（その間、調査や品番の行をロックしている）。「ピークメモリ」は経路の実行中に増えた PHP のメモリ。
- 3 回実行し、時間は中央値。SQL の数は 3 回とも同じだった。環境: PHP 8.3、ローカルの開発環境（WSL2）。時間は環境の負荷で揺れるので、比較は同じ環境で前後を測って行う。

## 結果

| 経路 | 所要時間 | SQL | SELECT | INSERT | UPDATE | DELETE | SQLの実行時間 | トランザクション | ピークメモリ |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 品番グループの保存（新規・50品番） | 757 ms | 589 | 54 | 484 | 0 | 51 | 29 ms | 529 ms | +5.5 MB |
| 品番グループの保存（変更なしで保存し直す・50品番） | 551 ms | 539 | 55 | 433 | 0 | 51 | 26 ms | 450 ms | +1.3 MB |
| Amazon在庫取込（調査を新規作成） | 4,230 ms | 3,120 | 25 | 2,307 | 675 | 113 | 176 ms | 3,942 ms | +9.3 MB |
| 取込（3ファイル、Amazon在庫あり） | 4,738 ms | 2,561 | 27 | 1,749 | 672 | 113 | 171 ms | 4,197 ms | +10.3 MB |
| 同日の追加取込（1ファイル） | 4,064 ms | 2,447 | 27 | 1,747 | 559 | 114 | 147 ms | 3,617 ms | +7.6 MB |
| 再照合 | 4,293 ms | 2,456 | 31 | 1,749 | 560 | 116 | 153 ms | 3,813 ms | +7.7 MB |
| Amazon在庫の取り直し（既存の調査に反映） | 2,797 ms | 2,178 | 14 | 1,377 | 675 | 112 | 109 ms | 2,616 ms | +4.3 MB |
| 取込（3ファイル、調査を新規作成・Amazon在庫なし） | 3,006 ms | 1,999 | 24 | 1,750 | 112 | 113 | 117 ms | 2,599 ms | +9.1 MB |

書き込みの内訳（10 回以上のもの）:

| 経路 | 内訳 |
| --- | --- |
| 品番グループの保存（新規・50品番） | insert skus 383、insert products 50、delete skus 50、insert import_targets 50 |
| 品番グループの保存（変更なしで保存し直す・50品番） | insert skus 383、delete skus 50、insert import_targets 50 |
| Amazon在庫取込（調査を新規作成） | insert skus 817、insert survey_skus 817、insert amazon_inventory_snapshots 559、update survey_skus 559、update products 112、delete skus 112、insert survey_products 112 |
| 取込（3ファイル、Amazon在庫あり） | insert skus 817、insert survey_skus 817、update survey_skus 559、update products 112、delete skus 112、insert survey_products 112 |
| 同日の追加取込（1ファイル） | insert skus 817、insert survey_skus 817、update survey_skus 559、delete skus 112、insert survey_products 112 |
| 再照合 | insert skus 817、insert survey_skus 817、update survey_skus 559、delete skus 112、insert survey_products 112 |
| Amazon在庫の取り直し（既存の調査に反映） | insert skus 817、insert amazon_inventory_snapshots 559、update survey_skus 559、update products 112、delete skus 112 |
| 取込（3ファイル、調査を新規作成・Amazon在庫なし） | insert skus 817、insert survey_skus 817、update products 112、delete skus 112、insert survey_products 112 |

計測では時刻を止めているため、同じ時刻に続けて実行した経路（同日の追加取込・再照合）では品番マスタの `synced_at` が変わらず、`update products 112` が出ていない。実際の運用では時刻が進むので、どの経路でも 112 件の更新が加わる。

## 分かったこと

1. **時間の大半は SQL の実行ではなく、1 行ずつのモデル作成・更新にかかっている。** SQL の実行時間は所要時間の 4% 前後で、残りは Eloquent のモデルを1行ずつ作って保存する処理（イベント、型変換、タイムスタンプ）。SQLite はプロセス内で動くため SQL 1 本あたりの通信がない。本番の MySQL では SQL 1 本ごとに往復の時間が加わるので、文の数を減らす効果は本番のほうが大きい。
2. **どの取込経路でも、SKU マスタ 817 件を消して入れ直している（PERF-06）。** 中身が変わらなくても `delete skus` 112 回（品番ごと）と `insert skus` 817 回が毎回発生する。品番グループの保存でも同じ（50 品番で 383 件）。
3. **調査を書き直す経路では、調査の品番・SKU 929 行を作り直している（PERF-07）。** 1 ファイルの追加取込や再照合でも、3 ファイルの取込と同じ量を書く。
4. **Amazon 在庫の反映が 559 回の UPDATE になっている（PERF-07）。** 調査を作った直後に、同じトランザクションの中で 1 行ずつ書き直している。Amazon 在庫取込で調査を新規作成する経路でも、作成（817 行の INSERT）のあとに同じ 559 回の UPDATE が続く。
5. **トランザクションが 2.6〜4.2 秒続く。** その間、調査の行（Amazon の反映中は調査とスナップショット）をロックしている。2〜4 を減らすとこの時間も短くなる。
6. **ピークメモリは 10 MB 前後で、現在の規模では問題にならない。** ファイルの丸ごと読み込み（PERF-08）の影響は、20 MiB 近いファイルで別に測る必要がある（今回のファイルは実ファイルと同じ数百 KB）。

## PERF-06〜08 での比較のしかた

- 同じコマンドで、変更の前後をそれぞれ 3 回測り、中央値を比べる。SQL の数は毎回同じになるので、主な比較の指標にする。
- PERF-06（CrossWalker 同期）: `insert skus`・`delete skus`・`update products` が、変更のない品番で 0 になることを確かめる。
- PERF-07（調査の差分更新と Amazon 在庫）: `insert survey_skus`・`insert survey_products`・`update survey_skus` が、変更のない行で 0 に近づくことを確かめる。
- PERF-08（CSV の順次読込）: ピークメモリを、20 MiB 程度のファイルを使う経路で比べる（計測用テストに大きなファイルの経路を足す）。
