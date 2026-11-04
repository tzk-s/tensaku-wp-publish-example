<?php
/**
 * Plugin Name:       TENSAKU Publish Demo
 * Description:       よくある質問を「編集中の内容」と「公開中の内容」に分けて持ち、明示的な公開操作のときだけ公開用の REST API に反映する公開用デモです。データはすべて架空です。
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Text Domain:       tensaku-publish-demo
 *
 * 【全体の流れ】
 *   1. 管理画面「よくある質問（公開デモ）」で文言を直し「下書きを保存」を押す … 編集中の内容（tpd_faq_draft）だけが変わる
 *   2. 公開用の REST API（GET /wp-json/tensaku-demo/v1/faqs）は公開中の内容（tpd_faq_live）を返すので、まだ変わらない
 *   3. 管理者が「公開に反映」を押す … 編集中の内容を検証し直し、問題が無ければ公開中の内容へ写す
 *   4. 公開用の REST API が新しい内容を返す
 *
 * 【ファイル】
 *   includes/data.php  … 保存先・架空の初期データ・入力の無害化と検証・公開する形への変換・公開処理
 *   includes/admin.php … 管理画面（表示・保存・公開の各処理と権限・nonce の確認）
 *   includes/rest.php  … 公開用の読み取り専用 REST API
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const TPD_VERSION = '1.0.0';

require_once __DIR__ . '/includes/data.php';
require_once __DIR__ . '/includes/admin.php';
require_once __DIR__ . '/includes/rest.php';

register_activation_hook( __FILE__, 'tpd_activate' );
