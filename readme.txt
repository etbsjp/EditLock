=== ETBS Edit Conflict Guard ===
Contributors: etbsjp
Donate link: https://etbs.jp/product/donate/
Tags: post lock, concurrent editing, editorial workflow, multi author, save conflict
Requires PHP: 7.4
Tested up to: 7.1
Stable tag: 1.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Actually blocks the save, not just the notice: a per-post exclusive lock that stops a second editor from overwriting someone else's work.

== Description ==

WordPress already tells you when someone else is editing a post: the "currently editing" notice under the post title. That notice is only a warning, though. It does not stop the second editor from clicking Update or Publish, and it does not stop the save. If two people edit the same post around the same time, whoever saves last silently overwrites the other person's changes, and nothing in WordPress core prevents that.

ETBS Edit Conflict Guard adds a real, per-post exclusive lock on top of that notice. While one user has a post open for editing, it blocks every other user from saving it, and it tells them clearly why.

= How it works =

* Opening a post's edit screen tries to acquire a lock on that post (first person to open it gets the lock).
* While the edit screen stays open, the lock is automatically extended through the standard WordPress Heartbeat API.
* Clicking Save Draft, Update, or Publish (Classic Editor or Block Editor) checks the lock. If someone else holds it, the save is blocked and a modal explains why.
* The lock is released when you leave the edit screen, or when Heartbeat stops (for example the tab is closed) and the lock's expiration time passes. A Classic Editor save also releases it, because the screen reloads. A Block Editor save keeps it, because you keep editing in the same tab.
* Autosaves neither take nor release the lock, and the lock never blocks them.
* The block is enforced on the server as well, on the Classic Editor's post save and the Block Editor's REST save, so the lock still holds even with JavaScript disabled or the connection unreliable.
* In the Classic Editor, WordPress's own "currently editing" dialog no longer offers "Take over" while this plugin holds the lock for another account, because taking over would not move this plugin's lock. The dialog says who is protecting the post instead.

All post types that have an admin UI are covered by default (attachments are excluded); any of them can be excluded from the Settings screen. The lock check runs at the moment Save/Update/Publish is clicked. Opening the edit screen shows nothing extra, except a notice when the same account already has the post open in another edit screen.

= Settings =

Under Settings > Edit Conflict Guard:

* Lock expiration, in seconds.
* Post types to exclude from locking.
* Whether moving a locked post to trash is also blocked (off by default; when on, any move-to-trash of a locked post is blocked, even from the lock holder's own other tab — force delete is not affected).
* A live table of every post currently locked, with a per-row Force Release button for administrators.

= Known limitations =

Edit Conflict Guard is intentionally strict and, as a result, has a few rough edges worth knowing about up front:

1. **Block Editor trash notice.** When the "Move to trash" action is blocked, the server correctly rejects it (the post stays published), but the Block Editor itself may briefly show a "Moved to trash" success notice anyway. This is WordPress core's own optimistic UI, not a lock failure — reloading the post list confirms the post was never actually moved.
2. **Bulk trash actions.** If the trash guard is enabled and a bulk "Move to Trash" action from the post list includes a locked post, processing stops at that post; posts later in the batch are left unprocessed.
3. **Permanent deletion bypasses the trash guard.** The trash guard only covers moves to the trash. A permanent delete that skips the trash entirely — for example `wp.deletePost` over XML-RPC on a custom post type, which WordPress core deletes outright instead of trashing — is not blocked, even while the post is locked.
4. **No Multisite support.** Locks are not aware of, or shared across, sites in a Multisite network.
5. **Locks apply to the holder too.** By design, even the account that holds the lock is blocked from saving the same post from a second tab, window or device. This is deliberate, not a bug. The plugin can tell the lock is held by the same account, but a matching account does not prove it is the same person (accounts are sometimes shared), so both are treated the same way. The message says "the same account" for that reason.
6. **Autosave is not blocked, so the same account can overwrite itself.** WordPress core keeps another user's autosave away from the post itself by storing it as a separate revision. When one account has the same draft open in two tabs, however, both tabs' autosaves can overwrite the draft in turn. The notice shown when the second tab opens is the warning.
7. **Block Editor "Take over" button.** WordPress's own "Take over" button in the Block Editor cannot be removed by a plugin. Taking over moves WordPress's lock but not this plugin's, so saving stays blocked until the other editor closes their screen or an administrator force-releases the lock.
8. **Saves outside the admin screens are not blocked.** Front-end forms, WP-Cron, WP-CLI and XML-RPC are let through, because they cannot show a message and would otherwise fail on visitors' screens. Saves through the REST API and the admin screens (including Quick Edit and bulk edit) are still checked.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/etbs-edit-conflict-guard` directory, or install the plugin directly through the WordPress plugins screen.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Go to Settings > Edit Conflict Guard to review the lock expiration, excluded post types, and trash guard option.

== Frequently Asked Questions ==

= How is this different from the "Someone else is editing this" notice WordPress already shows? =

That built-in notice is informational only; it does not stop a second user from saving. This plugin adds an actual block: if someone else holds the lock, clicking Save/Update/Publish is refused (the request is rejected with an HTTP 409 response) and the user sees a modal explaining why, instead of silently overwriting the other person's changes.

= A lock seems stuck. How do I clear it? =

Locks release automatically once their expiration time (set under Settings > Edit Conflict Guard) passes without the holder's edit screen sending a Heartbeat, for example if their browser tab was closed without saving. If you need it cleared immediately, an administrator can open Settings > Edit Conflict Guard and use the Force Release button next to that post in the "Currently Locked Posts" table.

= Does this plugin work on Multisite? =

Not currently. Locks are per-site; there is no cross-site awareness in a Multisite network.

= I blocked a trash action, but the screen still said "Moved to trash". Did it fail? =

No — the post was not moved. This happens only in the Block Editor: WordPress core shows an optimistic success notice before the server response arrives, and the server has already rejected the request. Reloading the post list will show the post still in its original status.

= Can the person who holds the lock still lock themselves out? =

Yes, deliberately. If the same account opens the same post in a second tab, window or device, the second one is blocked from saving just like any other user would be. The plugin can tell the two belong to the same account, but that does not prove it is the same person, so it applies the same rule to everyone. Close the other edit screen and save again.

== Screenshots ==

1. The Edit Conflict Guard settings screen, showing the lock expiration field, the list of post types to exclude, and the trash guard toggle.
2. The "Currently Locked Posts" table on the settings screen, listing each locked post with its editor, lock time, expiration, and a Force Release button.
3. The save-blocked modal shown in the Classic Editor when another user already holds the lock on the post being saved.
4. The save-blocked modal shown in the Block Editor (Gutenberg) when another user already holds the lock on the post being saved.
5. The support links as they appear on the Plugins list row and in the footer of the Settings > Edit Conflict Guard screen.

== Changelog ==

= 1.2.0 =
* Fixed: an autosave no longer releases the lock, which left the post unprotected without any sign.
* Fixed: saving in the Block Editor no longer leaves the post unprotected for the rest of the editing session.
* Fixed: the Classic Editor's autosave no longer fails silently while you hold the lock.
* Fixed: the Block Editor's autosave no longer reports success without saving anything.
* Fixed: saves made outside the admin screens (front-end forms, WP-Cron, WP-CLI) are no longer stopped with an error screen.
* Fixed: the predecessor plugin (EditLock) is now detected even when its folder has been renamed.
* Changed: when a second tab of the same account blocks a save, the message now says so and what to do. It also appears when the second tab opens.
* Changed: the save-blocked dialog is reworded and has an accessible name.
* Changed: the Classic Editor's "Take over" button is hidden while another account holds this plugin's lock.

= 1.1.1 =
* Added a bundled Japanese translation. Translations delivered by translate.wordpress.org still take precedence once they are available.

= 1.1.0 =
* Renamed the plugin to ETBS Edit Conflict Guard.
* Translated all UI strings to English; translations are now handled through translate.wordpress.org instead of bundled files.
* Removed the bundled update checker (plugin-update-checker).
* Removed the dashboard widget.
* Removed the donation link from the Plugins list row.
* Moved the settings screen's inline script and styles into enqueued asset files.
* Renamed the plugin's PHP classes and constants so this plugin can be installed alongside its predecessor without a fatal error. Option keys and the lock table are unchanged, so existing settings are kept.
* The lock table and the cleanup task are now restored automatically if they go missing.

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

= 1.2.0 =
Fixes the lock being released by an autosave or a Block Editor save, which left posts unprotected without any warning. Also clearer messages when a second tab of the same account blocks a save.

= 1.0.3 =
Uninstalling in earlier versions deleted your saved lock-expiration and excluded-post-type settings. Update to keep your settings when uninstalling.
