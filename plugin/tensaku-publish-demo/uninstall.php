<?php
/**
 * プラグインを削除したとき、このデモが作った値を消す
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'tpd_faq_draft' );
delete_option( 'tpd_faq_live' );
delete_option( 'tpd_faq_published_at' );
