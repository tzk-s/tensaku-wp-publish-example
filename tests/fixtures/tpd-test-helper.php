<?php
/**
 * Copyright (c) 2026 tzk-s
 * License: GPL-2.0-or-later（本文は plugin/tensaku-publish-demo/LICENSE）
 *
 * テスト専用の mu-plugin（npm run playground:test のときだけマウントする。プラグイン本体には含めない）
 *
 * 保存の画面では不正な値を保存できないため、「公開に反映するときにも検証し直す」ことを確かめる目的で、
 * 管理者だけが編集中の内容を不正な値に書き換えられる入口を用意する。
 *   POST /wp-admin/admin-post.php  action=tpd_test_corrupt_draft
 * 一時的なテスト環境でだけ読み込むので、nonce の確認は省いている（本番のプラグインには入れない）。
 */

add_action(
	'admin_post_tpd_test_corrupt_draft',
	static function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden', '', array( 'response' => 403 ) );
		}
		//  表示する質問が 0 件の下書き（tpd_validate_faq で誤りになる）
		update_option(
			'tpd_faq_draft',
			array(
				'items' => array(
					array(
						'question' => '不正な下書き',
						'answer'   => '表示する質問が 0 件',
						'visible'  => false,
					),
				),
			),
			false
		);
		wp_die( 'corrupted', '', array( 'response' => 200 ) );
	}
);
