<?php
/**
 * Migration notice for the move to the wordpress.org release.
 * wordpress.org 版への移行案内。
 *
 * This self-distributed copy and the wordpress.org release (ETBS Edit Conflict Guard) live in
 * different plugin folders, so WordPress core can never offer one as an update to the other.
 * The switch has to be done by hand, and this class is the only channel we have to say so.
 *
 * 自社配布版と wordpress.org 版（ETBS Edit Conflict Guard）はプラグインのフォルダ名が異なるため、
 * コアが一方を他方の更新として提示することは決してない。切り替えは手で行うしかなく、
 * それを伝える経路はこのクラスだけである。
 *
 * @package EditLock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shows the migration notice and the permanent row link.
 * 移行案内と、一覧行の常設リンクを表示する。
 */
class Edlk_Migration_Notice {

	/**
	 * Slug of the wordpress.org release.
	 * wordpress.org 版のスラッグ。
	 */
	const SUCCESSOR_SLUG = 'etbs-edit-conflict-guard';

	/**
	 * Main file of the wordpress.org release, relative to the plugins directory.
	 * wordpress.org 版のメインファイル（プラグインディレクトリからの相対パス）。
	 */
	const SUCCESSOR_FILE = 'etbs-edit-conflict-guard/etbs-edit-conflict-guard.php';

	/**
	 * Public page of the wordpress.org release.
	 * wordpress.org 版の公開ページ。
	 */
	const SUCCESSOR_URL = 'https://wordpress.org/plugins/etbs-edit-conflict-guard/';

	/**
	 * User meta key that records "do not show this again" per user.
	 * 「今後表示しない」をユーザーごとに記録するユーザーメタのキー。
	 *
	 * A user meta is used on purpose: with an option, one user dismissing it would hide the
	 * notice from every other administrator as well.
	 * ユーザーメタにしているのは意図的。オプションにすると、1人が消した時点で他の管理者からも消える。
	 */
	const DISMISSED_META = 'edlk_migration_notice_dismissed';

	/**
	 * Action name used by the "do not show this again" link.
	 * 「今後表示しない」リンクが使うアクション名。
	 */
	const DISMISS_ACTION = 'edlk_dismiss_migration_notice';

	/**
	 * Registers the hooks.
	 * フックを登録する。
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( __CLASS__, 'handle_dismiss' ) );
		// Priority 11 so this link comes after the existing "Request development" link.
		// 既存の「開発のご依頼」リンクの後ろに並べるため優先度 11 で登録する.
		add_filter( 'plugin_row_meta', array( __CLASS__, 'add_row_meta' ), 11, 2 );
	}

	/**
	 * Reports whether the wordpress.org release is present on this site.
	 * wordpress.org 版がこのサイトに設置済みかどうかを返す。
	 *
	 * File existence is checked instead of asking whether the plugin is active, because the
	 * successor refuses to load while this plugin is active. Asking "is it active" would answer
	 * "no" for exactly the site that needs the follow-up message.
	 * 有効かどうかではなくファイルの有無を見ている。後継はこのプラグインが有効な間は読み込みを
	 * 拒否するため、「有効か」で判定すると、続きの案内が最も必要なサイトで「いいえ」になる。
	 *
	 * @return bool True when the successor's main file exists.
	 */
	public static function is_successor_installed() {
		return file_exists( WP_PLUGIN_DIR . '/' . self::SUCCESSOR_FILE );
	}

	/**
	 * Reports whether the notice should appear on the screen being rendered.
	 * いま描画されている画面に案内を出すべきかを返す。
	 *
	 * Only the plugins list and this plugin's own settings screen are used. The notice is not
	 * shown across the whole admin: this plugin's value is that it stays out of the way.
	 * 表示先はプラグイン一覧と本プラグインの設定画面だけ。全管理画面には出さない。
	 * このプラグインの価値は「邪魔をしないこと」であり、全画面通知はそれと衝突する。
	 *
	 * @return bool True on the plugins list or this plugin's settings screen.
	 */
	private static function is_target_screen() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();
		if ( ! $screen ) {
			return false;
		}

		$allowed = array( 'plugins', 'plugins-network', 'settings_page_edlk-settings' );

		return in_array( $screen->id, $allowed, true );
	}

	/**
	 * Reports whether the current user has hidden the notice.
	 * 現在のユーザーが案内を非表示にしているかを返す。
	 *
	 * @return bool True when this user chose "do not show this again".
	 */
	private static function is_dismissed() {
		return (bool) get_user_meta( get_current_user_id(), self::DISMISSED_META, true );
	}

	/**
	 * Builds the best available link for installing the wordpress.org release.
	 * wordpress.org 版を入手するための、最も確実なリンクを組み立てる。
	 *
	 * The in-admin link addresses the plugin by slug, so it does not depend on the directory's
	 * search index having caught up. Users who cannot install plugins get the public page instead.
	 * 管理画面内のリンクはスラッグ直指定なので、ディレクトリの検索索引の反映を待たない。
	 * プラグインを追加できない権限のユーザーには公開ページを渡す。
	 *
	 * @return string URL to send the user to.
	 */
	private static function get_install_url() {
		if ( current_user_can( 'install_plugins' ) ) {
			return admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . self::SUCCESSOR_SLUG );
		}

		return self::SUCCESSOR_URL;
	}

	/**
	 * Builds the URL of the "do not show this again" link.
	 * 「今後表示しない」リンクの URL を組み立てる。
	 *
	 * @return string Nonce-protected URL pointing at admin-post.php.
	 */
	private static function get_dismiss_url() {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ),
			self::DISMISS_ACTION
		);
	}

	/**
	 * Prints the migration notice.
	 * 移行案内を出力する。
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( ! self::is_target_screen() ) {
			return;
		}

		if ( self::is_successor_installed() ) {
			// The user has already started the switch. This is the last step, so it is shown
			// even to users who hid the first notice.
			// すでに切り替えを始めている。最後の1手なので、最初の案内を消したユーザーにも出す.
			self::render_last_step();
			return;
		}

		if ( self::is_dismissed() ) {
			return;
		}

		self::render_announcement();
	}

	/**
	 * Prints the notice shown before the wordpress.org release is installed.
	 * wordpress.org 版が未設置のサイトに出す案内を出力する。
	 *
	 * @return void
	 */
	private static function render_announcement() {
		// The product name is a brand name and is not translated.
		// 製品名はブランド名なので翻訳対象にしない.
		$successor_link = '<a href="' . esc_url( self::get_install_url() ) . '"><strong>'
			. esc_html( 'ETBS Edit Conflict Guard' ) . '</strong></a>';
		?>
		<div class="notice notice-info">
			<p>
				<strong><?php esc_html_e( 'EditLock is now on WordPress.org as "ETBS Edit Conflict Guard".', 'editlock' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'It is the same plugin by the same author, renamed so it could be listed in the official plugin directory.', 'editlock' ); ?>
				<?php esc_html_e( 'New versions are published on WordPress.org from now on.', 'editlock' ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'How to switch over - your settings are kept:', 'editlock' ); ?></strong>
			</p>
			<ol>
				<li>
					<?php
					printf(
						/* translators: %s: wordpress.org 版へのリンク */
						esc_html__( 'Install and activate %s from the plugin directory.', 'editlock' ),
						$successor_link // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built above from esc_url() and esc_html__().
					);
					?>
				</li>
				<li>
					<?php esc_html_e( 'Deactivate EditLock.', 'editlock' ); ?>
					<?php esc_html_e( 'The new plugin starts working the moment EditLock is deactivated.', 'editlock' ); ?>
				</li>
				<li><?php esc_html_e( 'Delete EditLock.', 'editlock' ); ?></li>
			</ol>
			<p>
				<?php esc_html_e( 'Your lock expiration, excluded post types and trash protection settings are carried over automatically.', 'editlock' ); ?>
				<?php esc_html_e( 'Please switch while nobody is editing a post: locks held at that moment are released during the switch.', 'editlock' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( self::get_dismiss_url() ); ?>">
					<?php esc_html_e( 'Do not show this again', 'editlock' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Prints the notice shown when the successor is installed but this plugin is still active.
	 * 後継が設置済みなのに本プラグインが有効なままのサイトに出す案内を出力する。
	 *
	 * The successor shows an equivalent message from its own side, but it cannot run at all
	 * while this plugin is active, so the message has to come from here as well.
	 * 後継も同趣旨の通知を自分の側から出すが、本プラグインが有効な間はそもそも動けないため、
	 * こちら側からも言う必要がある。
	 *
	 * @return void
	 */
	private static function render_last_step() {
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'ETBS Edit Conflict Guard is installed but not running, because EditLock is still active.', 'editlock' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'Deactivate EditLock to finish the switch.', 'editlock' ); ?>
				<?php esc_html_e( 'Your saved settings are kept.', 'editlock' ); ?>
				<?php esc_html_e( 'Delete EditLock after the new plugin is running.', 'editlock' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Records "do not show this again" for the current user and returns them to the page.
	 * 「今後表示しない」を現在のユーザーに記録し、元の画面へ戻す。
	 *
	 * @return void
	 */
	public static function handle_dismiss() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'editlock' ) );
		}

		check_admin_referer( self::DISMISS_ACTION );

		update_user_meta( get_current_user_id(), self::DISMISSED_META, 1 );

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'plugins.php' ) );
		exit;
	}

	/**
	 * Adds a permanent link to the plugin's row on the plugins list.
	 * プラグイン一覧の行に、常設のリンクを追加する。
	 *
	 * This link is deliberately not dismissible. It carries no urgency and takes no space, so it
	 * remains as a quiet pointer after the notice above has been hidden.
	 * このリンクは意図的に消せないようにしている。急かす要素も場所も取らないため、
	 * 上の案内を非表示にした後も静かな案内として残す。
	 *
	 * @param array  $links Row meta links already registered for this row.
	 * @param string $file  Plugin file the row belongs to.
	 * @return array Row meta links, with ours appended when the row is ours.
	 */
	public static function add_row_meta( $links, $file ) {
		if ( plugin_basename( EDLK_PLUGIN_FILE ) !== $file ) {
			return $links;
		}

		$links[] = '<a href="' . esc_url( self::SUCCESSOR_URL ) . '" target="_blank" rel="noopener noreferrer">'
			. esc_html__( 'Moving to WordPress.org', 'editlock' ) . '</a>';

		return $links;
	}
}

Edlk_Migration_Notice::init();
