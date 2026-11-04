<?php
/**
 * 管理画面「よくある質問（公開デモ）」— 表示・保存・公開
 *
 * 【誰が何をできるか】
 *   下書きを保存する … edit_pages を持つ人（標準の役割では編集者・管理者）
 *   公開に反映する   … tpd_publish_faq を持つ人。このデモでは manage_options を持つ人（管理者）にだけ与える
 *   どちらの操作も、権限（current_user_can）と nonce（check_admin_referer）を別々に確認してから処理する。
 *   公開の権限を分けているだけで、別の担当者が承認する仕組み（承認待ちの状態や差し戻し）は無い。
 *   管理者は自分で保存して、そのまま公開できる。
 *
 * 【入力に誤りがあるとき】保存せずに画面へ戻し、入力した内容（無害化済み）と誤りを 10 分だけ一時保存して表示し直す。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const TPD_PAGE        = 'tpd-faq';
const TPD_EDIT_CAP    = 'edit_pages';
const TPD_PUBLISH_CAP = 'tpd_publish_faq';
/** 追加用に出す空欄の行数 */
const TPD_BLANK_ROWS = 2;

//  管理者に「公開に反映」の権限を与える（役割に書き込まないので、プラグインを止めれば権限も消える）
add_filter(
	'user_has_cap',
	static function ( array $allcaps ): array {
		if ( ! empty( $allcaps['manage_options'] ) ) {
			$allcaps[ TPD_PUBLISH_CAP ] = true;
		}
		return $allcaps;
	}
);

add_action(
	'admin_menu',
	static function () {
		add_menu_page( 'よくある質問（公開デモ）', 'よくある質問（公開デモ）', TPD_EDIT_CAP, TPD_PAGE, 'tpd_render_page', 'dashicons-editor-help', 6 );
	}
);

add_action( 'admin_post_tpd_save', 'tpd_handle_save' );
add_action( 'admin_post_tpd_publish', 'tpd_handle_publish' );

/** 画面に戻したときの一時保存のキー（ユーザーごと） */
function tpd_flash_key(): string {
	return 'tpd_flash_' . get_current_user_id();
}

function tpd_page_url( array $args = array() ): string {
	return add_query_arg( $args, admin_url( 'admin.php?page=' . TPD_PAGE ) );
}

/* --------------------------------------------------------------------------
 * 保存（下書き）
 * ------------------------------------------------------------------------ */

function tpd_handle_save(): void {
	if ( ! current_user_can( TPD_EDIT_CAP ) ) {
		wp_die( esc_html__( 'この操作を行う権限がありません。', 'tensaku-publish-demo' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'tpd_save' );

	// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tpd_sanitize_faq で項目ごとに無害化する
	$input  = isset( $_POST['tpd']['items'] ) && is_array( $_POST['tpd']['items'] ) ? wp_unslash( $_POST['tpd']['items'] ) : array();
	$value  = tpd_sanitize_faq( $input );
	$errors = tpd_validate_faq( $value );

	if ( $errors ) {
		set_transient(
			tpd_flash_key(),
			array(
				'value'  => $value,
				'errors' => $errors,
			),
			10 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( tpd_page_url( array( 'tpd_notice' => 'invalid' ) ) );
		exit;
	}
	//  公開中の内容（tpd_faq_live）には触れない
	update_option( TPD_DRAFT_OPTION, $value, false );
	wp_safe_redirect( tpd_page_url( array( 'tpd_notice' => 'saved' ) ) );
	exit;
}

/* --------------------------------------------------------------------------
 * 公開に反映
 * ------------------------------------------------------------------------ */

function tpd_handle_publish(): void {
	if ( ! current_user_can( TPD_PUBLISH_CAP ) ) {
		wp_die( esc_html__( '公開に反映する権限がありません。', 'tensaku-publish-demo' ), '', array( 'response' => 403 ) );
	}
	check_admin_referer( 'tpd_publish' );

	$errors = tpd_publish();
	if ( $errors ) {
		set_transient( tpd_flash_key(), array( 'errors' => $errors ), 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( tpd_page_url( array( 'tpd_notice' => 'publish_failed' ) ) );
		exit;
	}
	wp_safe_redirect( tpd_page_url( array( 'tpd_notice' => 'published' ) ) );
	exit;
}

/* --------------------------------------------------------------------------
 * 画面
 * ------------------------------------------------------------------------ */

/** 画面上部のお知らせ。表示する文言は固定の一覧から選ぶ（URL の値をそのまま出さない） */
function tpd_render_notice( array $errors ): void {
	$notices = array(
		'saved'          => array( 'success', '下書きを保存しました。公開用の REST API はまだ変わりません。' ),
		'published'      => array( 'success', '公開に反映しました。公開用の REST API が新しい内容を返します。' ),
		'invalid'        => array( 'error', '保存していません。次の点を直してから、もう一度保存してください。' ),
		'publish_failed' => array( 'error', '公開に反映していません（公開中の内容はそのままです）。下書きの次の点を直してください。' ),
	);
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- 表示の切り替えのみ
	$key = isset( $_GET['tpd_notice'] ) ? sanitize_key( wp_unslash( $_GET['tpd_notice'] ) ) : '';
	if ( ! isset( $notices[ $key ] ) ) {
		return;
	}
	[ $type, $message ] = $notices[ $key ];
	printf( '<div class="notice notice-%1$s"><p>%2$s</p>', esc_attr( $type ), esc_html( $message ) );
	if ( $errors ) {
		echo '<ul class="ul-disc">';
		foreach ( $errors as $error ) {
			echo '<li>' . esc_html( $error ) . '</li>';
		}
		echo '</ul>';
	}
	echo '</div>';
}

function tpd_render_page(): void {
	if ( ! current_user_can( TPD_EDIT_CAP ) ) {
		return;
	}
	$flash = get_transient( tpd_flash_key() );
	delete_transient( tpd_flash_key() );
	$flash  = is_array( $flash ) ? $flash : array();
	$errors = isset( $flash['errors'] ) && is_array( $flash['errors'] ) ? $flash['errors'] : array();
	$value  = isset( $flash['value'] ) && is_array( $flash['value'] ) ? $flash['value'] : tpd_get_draft();

	echo '<div class="wrap">';
	echo '<h1>よくある質問（公開デモ）</h1>';
	tpd_render_notice( $errors );

	echo '<p>「下書きを保存」で変わるのは編集中の内容だけです。公開用の REST API（'
		. '<a href="' . esc_url( rest_url( 'tensaku-demo/v1/faqs' ) ) . '" target="_blank" rel="noopener"><code>/wp-json/tensaku-demo/v1/faqs</code><span class="screen-reader-text">（新しいタブで開きます）</span></a>'
		. '）は、「公開に反映」を押すまで公開中の内容を返し続けます。</p>';

	tpd_render_status();
	tpd_render_edit_form( $value );
	tpd_render_publish_form();
	tpd_render_live();
	echo '</div>';
}

function tpd_render_status(): void {
	$published_at = get_option( TPD_PUBLISHED_AT_OPTION );
	$when         = is_string( $published_at ) ? wp_date( 'Y-m-d H:i', strtotime( $published_at ) ) : '有効化したときの初期データ';
	printf(
		'<p><strong>状態：</strong>%1$s　<strong>最後に公開した日時：</strong>%2$s</p>',
		esc_html( tpd_has_unpublished_changes() ? '公開していない変更があります' : '公開中の内容と同じです' ),
		esc_html( $when )
	);
}

function tpd_render_edit_form( array $value ): void {
	$rows = array_values( $value['items'] );
	for ( $i = 0; $i < TPD_BLANK_ROWS; $i++ ) {
		$rows[] = array(
			'question' => '',
			'answer'   => '',
			'visible'  => true,
		);
	}

	echo '<h2>編集中の内容</h2>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="tpd_save">';
	wp_nonce_field( 'tpd_save' );
	echo '<p class="description">質問と回答の両方を空にした行は保存しません（削除になります）。空欄の行は追加用です。</p>';
	echo '<table class="form-table" role="presentation"><tbody>';
	foreach ( $rows as $index => $item ) {
		$id   = 'tpd-item-' . $index;
		$name = 'tpd[items][' . $index . ']';
		printf(
			'<tr><th scope="row">%1$d 問目</th><td>'
			. '<p><label for="%2$s-q">質問</label><br><input type="text" id="%2$s-q" name="%3$s[question]" value="%4$s" class="large-text" maxlength="%5$d"></p>'
			. '<p><label for="%2$s-a">回答</label><br><textarea id="%2$s-a" name="%3$s[answer]" rows="3" class="large-text">%6$s</textarea></p>'
			. '<p><label><input type="checkbox" name="%3$s[visible]" value="1"%7$s> 公開サイトに表示する</label></p>'
			. '</td></tr>',
			(int) $index + 1,
			esc_attr( $id ),
			esc_attr( $name ),
			esc_attr( (string) $item['question'] ),
			(int) TPD_QUESTION_LIMIT,
			esc_textarea( (string) $item['answer'] ),
			checked( ! empty( $item['visible'] ), true, false )
		);
	}
	echo '</tbody></table>';
	submit_button( '下書きを保存', 'secondary', 'submit', true, array( 'id' => 'tpd-save' ) );
	echo '</form>';
}

function tpd_render_publish_form(): void {
	echo '<h2>公開に反映</h2>';
	if ( ! current_user_can( TPD_PUBLISH_CAP ) ) {
		echo '<p>公開に反映できるのは管理者だけです。下書きを保存したら、管理者に反映を依頼してください。</p>';
		return;
	}
	echo '<p>編集中の内容を検証し直し、問題が無ければ公開中の内容を置き換えます。誤りがあれば、公開中の内容はそのまま残ります。</p>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	echo '<input type="hidden" name="action" value="tpd_publish">';
	wp_nonce_field( 'tpd_publish' );
	submit_button( '公開に反映', 'primary', 'submit', true, array( 'id' => 'tpd-publish' ) );
	echo '</form>';
}

function tpd_render_live(): void {
	echo '<h2>公開中の内容（公開用の REST API が返す内容）</h2>';
	$items = tpd_public_faq( tpd_get_live() );
	if ( ! $items ) {
		echo '<p>公開中の質問はありません。</p>';
		return;
	}
	echo '<dl class="tpd-live">';
	foreach ( $items as $item ) {
		echo '<dt><strong>' . esc_html( $item['question'] ) . '</strong></dt>';
		echo '<dd>' . nl2br( esc_html( $item['answer'] ) ) . '</dd>';
	}
	echo '</dl>';
}
