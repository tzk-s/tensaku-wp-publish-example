<?php
/**
 * 公開用の REST API（読み取り専用）
 *
 *   GET /wp-json/tensaku-demo/v1/faqs
 *   → { "items": [ { "question": "…", "answer": "…" } ], "publishedAt": "2026-11-04T00:00:00+00:00" | null }
 *
 * 公開中の内容（tpd_faq_live）だけを読む。認証なしで読めるので、公開してよい項目だけを組み立てて返す。
 * 編集中の内容を返す入口は作らない（このデモでは、編集中の内容は管理画面でだけ確認する）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'tensaku-demo/v1',
			'/faqs',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => 'tpd_rest_faqs',
				//  公開済みの内容だけを返すので、誰でも読める
				'permission_callback' => '__return_true',
			)
		);
	}
);

function tpd_rest_faqs(): WP_REST_Response {
	$published_at = get_option( TPD_PUBLISHED_AT_OPTION );
	return rest_ensure_response(
		array(
			'items'       => tpd_public_faq( tpd_get_live() ),
			'publishedAt' => is_string( $published_at ) ? $published_at : null,
		)
	);
}
