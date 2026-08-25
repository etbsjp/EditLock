=== EditLock ===
Contributors: etbsjp
Donate link: https://etbs.jp/product/donate/
Tags: post lock, concurrent editing, editorial workflow, multi author, save conflict
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Actually blocks the save, not just the notice: a per-post exclusive lock that stops a second editor from overwriting someone else's work.

== Description ==

WordPress already tells you when someone else is editing a post: the "currently editing" notice under the post title. That notice is only a warning, though. It does not stop the second editor from clicking Update or Publish, and it does not stop the save. If two people edit the same post around the same time, whoever saves last silently overwrites the other person's changes, and nothing in WordPress core prevents that.

EditLock adds a real, per-post exclusive lock on top of that notice. While one user has a post open for editing, EditLock blocks every other user from saving it, and it tells them clearly why.

= How it works =

* Opening a post's edit screen tries to acquire a lock on that post (first person to open it gets the lock).
* While the edit screen stays open, the lock is automatically extended through the standard WordPress Heartbeat API.
* Clicking Save Draft, Update, or Publish (Classic Editor or Block Editor) checks the lock. If someone else holds it, the save is blocked and a modal explains why.
* The lock is released automatically when the save succeeds, or when Heartbeat stops (for example the tab is closed) and the lock's expiration time passes.
* The block is enforced on the server as well, on the Classic Editor's post save and the Block Editor's REST save, so the lock still holds even with JavaScript disabled or the connection unreliable.

All post types that have an admin UI are covered by default (attachments are excluded); any of them can be excluded from the Settings screen. The lock check only runs at the moment Save/Update/Publish is clicked — simply opening the edit screen shows nothing extra.

= Settings =

Under Settings > EditLock:

* Lock expiration, in seconds.
* Post types to exclude from locking.
* Whether moving a locked post to trash is also blocked (off by default; when on, any move-to-trash of a locked post is blocked, even from the lock holder's own other tab — force delete is not affected).
* A live table of every post currently locked, with a per-row Force Release button for administrators.

= Known limitations =

EditLock is intentionally strict and, as a result, has a few rough edges worth knowing about up front:

1. **Block Editor trash notice.** When the "Move to trash" action is blocked, the server correctly rejects it (the post stays published), but the Block Editor itself may briefly show a "Moved to trash" success notice anyway. This is WordPress core's own optimistic UI, not a lock failure — reloading the post list confirms the post was never actually moved.
2. **Bulk trash actions.** If the trash guard is enabled and a bulk "Move to Trash" action from the post list includes a locked post, processing stops at that post; posts later in the batch are left unprocessed.
3. **Permanent deletion bypasses the trash guard.** The trash guard only covers moves to the trash. A permanent delete that skips the trash entirely — for example `wp.deletePost` over XML-RPC on a custom post type, which WordPress core deletes outright instead of trashing — is not blocked, even while the post is locked.
4. **No Multisite support.** Locks are not aware of, or shared across, sites in a Multisite network.
5. **Locks apply to the holder too.** By design, even the user who holds the lock is blocked from saving the same post from a second tab or window. This is deliberate, not a bug — EditLock cannot tell two tabs from two different editors.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/editlock` directory, or install the plugin directly through the WordPress plugins screen.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to Settings > EditLock to review the lock expiration, excluded post types, and trash guard option.

== Frequently Asked Questions ==

= How is this different from the "Someone else is editing this" notice WordPress already shows? =

That built-in notice is informational only; it does not stop a second user from saving. EditLock adds an actual block: if someone else holds the lock, clicking Save/Update/Publish is refused (the request is rejected with an HTTP 409 response) and the user sees a modal explaining why, instead of silently overwriting the other person's changes.

= A lock seems stuck. How do I clear it? =

Locks release automatically once their expiration time (set under Settings > EditLock) passes without the holder's edit screen sending a Heartbeat, for example if their browser tab was closed without saving. If you need it cleared immediately, an administrator can open Settings > EditLock and use the Force Release button next to that post in the "Currently Locked Posts" table.

= Does EditLock work on Multisite? =

Not currently. Locks are per-site; there is no cross-site awareness in a Multisite network.

= I blocked a trash action, but the screen still said "Moved to trash". Did it fail? =

No — the post was not moved. This happens only in the Block Editor: WordPress core shows an optimistic success notice before the server response arrives, and the server has already rejected the request. Reloading the post list will show the post still in its original status.

= Can the person who holds the lock still lock themselves out? =

Yes, deliberately. If the same user opens the same post in a second tab, the second tab is blocked from saving just like any other user would be. EditLock has no way to tell that both tabs belong to the same person, so it applies the same rule to everyone.

== Screenshots ==

1. The EditLock settings screen, showing the lock expiration field, the list of post types to exclude, and the trash guard toggle.
2. The "Currently Locked Posts" table on the settings screen, listing each locked post with its editor, lock time, expiration, and a Force Release button.
3. The save-blocked modal shown in the Classic Editor when another user already holds the lock on the post being saved.
4. The save-blocked modal shown in the Block Editor (Gutenberg) when another user already holds the lock on the post being saved.
5. The support links as they appear on the Plugins list row and in the footer of the Settings > EditLock screen.

== Changelog ==

= 1.1.0 =
* Translated all UI strings to English; Japanese is now provided via the bundled languages/editlock-ja.po and .mo files.
* Removed the bundled update checker (plugin-update-checker).
* Removed the dashboard widget.
* Removed the donation link from the Plugins list row.
* Changed the Plugin URI to the product page (https://etbs.jp/product/editlock/).

= 1.0.3 =
* Fix: uninstalling the plugin no longer deletes settings you configured (lock expiration, excluded post types). Only the plugin's own temporary lock data and its cleanup cron event are removed.

= 1.0.2 =
* Removed the "Requires at least" (WordPress) declaration. No verified minimum WordPress version exists for the APIs this plugin uses.

= 1.0.1 =
* Lowered the declared "Requires PHP" from 8.3 to 7.4.
* Added a "Request development" / donation link on the Plugins list row and the settings page footer.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.0 =
This is the final update delivered from GitHub. EditLock is now on the WordPress.org plugin directory and updates arrive from there automatically from now on. No action is needed. ／ GitHub からお届けする最後の更新です。今後の更新は WordPress.org から自動で届きます。操作は不要です。

= 1.0.3 =
Uninstalling in earlier versions deleted your saved lock-expiration and excluded-post-type settings. Update to keep your settings when uninstalling.
