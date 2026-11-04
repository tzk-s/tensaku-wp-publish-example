<?php
/**
 * よくある質問のデータ — 保存先・入力の無害化と検証・公開する形への変換・公開処理
 *
 * 【保存先】wp_options に 2 つ持つ。どちらも配列 { "items": [ { "question", "answer", "visible" } ] }
 *   tpd_faq_draft … 編集中の内容。「下書きを保存」で書き換わる。管理画面だけが読む
 *   tpd_faq_live  … 公開中の内容。「公開に反映」でだけ書き換わる。公開用の REST API はこちらだけを読む
 *   2 つに分けるのは、確かめる前の変更が公開用の値に出ないようにするため。
 *   どちらも register_setting() しないので、標準の /wp/v2/settings には出ない。
 *
 * 【公開に反映するとき】編集中の内容を保存時と同じ規則で検証し直す。誤りがあれば公開中の内容は書き換えない。
 *   通常は保存の時点で検証済みだが、規則の変更や直接の書き換えで不正な値が残っていても、公開用の値に出さないため。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const TPD_DRAFT_OPTION        = 'tpd_faq_draft';
const TPD_LIVE_OPTION         = 'tpd_faq_live';
const TPD_PUBLISHED_AT_OPTION = 'tpd_faq_published_at';

/** 質問の件数と文字数の上限 */
const TPD_MAX_ITEMS      = 10;
const TPD_QUESTION_LIMIT = 100;
const TPD_ANSWER_LIMIT   = 1000;

/** 架空の初期データ（有効化したとき、編集中と公開中の両方に入れる） */
function tpd_sample_faq(): array {
	return array(
		'items' => array(
			array(
				'question' => '無料で試せますか？',
				'answer'   => "はい。登録から 14 日間、すべての機能を無料でお試しいただけます。\n期間が終わっても自動で課金されることはありません。",
				'visible'  => true,
			),
			array(
				'question' => 'クライアントにもアカウントが必要ですか？',
				'answer'   => 'いいえ。共有用のリンクを送れば、ゲストとしてコメントできます。',
				'visible'  => true,
			),
			array(
				'question' => '社内用のメモは残せますか？',
				'answer'   => 'チーム内だけに見えるコメントを残せます。（このデモでは非表示にしています）',
				'visible'  => false,
			),
		),
	);
}

/** 有効化したとき。すでに値があれば上書きしない（再有効化で編集内容を消さないため） */
function tpd_activate(): void {
	$sample = tpd_sample_faq();
	//  毎回のページ表示で読む値ではないので autoload はしない
	add_option( TPD_DRAFT_OPTION, $sample, '', false );
	add_option( TPD_LIVE_OPTION, $sample, '', false );
}

/** 保存済みの値。壊れていれば空の形にする */
function tpd_read_option( string $option ): array {
	$value = get_option( $option );
	return is_array( $value ) && isset( $value['items'] ) && is_array( $value['items'] ) ? $value : array( 'items' => array() );
}

function tpd_get_draft(): array {
	return tpd_read_option( TPD_DRAFT_OPTION );
}

function tpd_get_live(): array {
	return tpd_read_option( TPD_LIVE_OPTION );
}

/** 公開していない変更があるか */
function tpd_has_unpublished_changes(): bool {
	return tpd_get_draft() !== tpd_get_live();
}

/**
 * 送信値を保存の形にする（無害化だけを行い、誤りの判定は tpd_validate_faq に任せる）。
 * 質問・回答の両方が空の行（追加用の空欄）は捨てる。
 */
function tpd_sanitize_faq( array $input ): array {
	$items = array();
	foreach ( $input as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$question = sanitize_text_field( (string) ( $row['question'] ?? '' ) );
		//  ブラウザーは改行を \r\n で送るので、\n にそろえる（REST の応答と、変更の有無の判定を安定させるため）
		$answer   = str_replace( array( "\r\n", "\r" ), "\n", sanitize_textarea_field( (string) ( $row['answer'] ?? '' ) ) );
		if ( '' === $question && '' === $answer ) {
			continue;
		}
		$items[] = array(
			'question' => $question,
			'answer'   => $answer,
			'visible'  => ! empty( $row['visible'] ),
		);
	}
	return array( 'items' => $items );
}

/** 誤りの一覧（空なら保存・公開してよい） */
function tpd_validate_faq( array $value ): array {
	$errors = array();
	$items  = isset( $value['items'] ) && is_array( $value['items'] ) ? $value['items'] : null;
	if ( null === $items ) {
		return array( 'データの形が正しくありません。' );
	}
	if ( count( $items ) > TPD_MAX_ITEMS ) {
		$errors[] = sprintf( '質問は %d 件までです。', TPD_MAX_ITEMS );
	}
	$visible = 0;
	foreach ( array_values( $items ) as $index => $item ) {
		$label    = sprintf( '%d 問目', $index + 1 );
		$question = is_array( $item ) && is_string( $item['question'] ?? null ) ? $item['question'] : '';
		$answer   = is_array( $item ) && is_string( $item['answer'] ?? null ) ? $item['answer'] : '';
		if ( '' === trim( $question ) || '' === trim( $answer ) ) {
			$errors[] = $label . '：質問と回答の両方を入力してください。';
		}
		if ( mb_strlen( $question ) > TPD_QUESTION_LIMIT ) {
			$errors[] = sprintf( '%s：質問は %d 文字以内にしてください。', $label, TPD_QUESTION_LIMIT );
		}
		if ( mb_strlen( $answer ) > TPD_ANSWER_LIMIT ) {
			$errors[] = sprintf( '%s：回答は %d 文字以内にしてください。', $label, TPD_ANSWER_LIMIT );
		}
		$visible += is_array( $item ) && ! empty( $item['visible'] ) ? 1 : 0;
	}
	//  0 件だと公開サイトの「よくある質問」が空になるので止める
	if ( 0 === $visible ) {
		$errors[] = '「公開サイトに表示する」質問を 1 件以上にしてください。';
	}
	return $errors;
}

/** 公開用の REST API で返す形（表示する質問だけ。visible などの管理用の項目は出さない） */
function tpd_public_faq( array $value ): array {
	$items = array();
	foreach ( $value['items'] as $item ) {
		if ( ! empty( $item['visible'] ) ) {
			$items[] = array(
				'question' => (string) $item['question'],
				'answer'   => (string) $item['answer'],
			);
		}
	}
	return $items;
}

/**
 * 編集中の内容を公開中の内容へ写す（「公開に反映」）。
 * 誤りがあれば何も書き換えずに誤りの一覧を返す。成功したら空の配列。
 */
function tpd_publish(): array {
	$draft  = tpd_get_draft();
	$errors = tpd_validate_faq( $draft );
	if ( $errors ) {
		return $errors;
	}
	update_option( TPD_LIVE_OPTION, $draft, false );
	update_option( TPD_PUBLISHED_AT_OPTION, gmdate( 'c' ), false );
	return array();
}
