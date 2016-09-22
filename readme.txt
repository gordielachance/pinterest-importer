=== Pinterest Importer ===
Contributors:grosbouff
Donate link:http://bit.ly/gbreant
Tags: importer,Pinterest,pins,backup
Requires at least: 3.5
Tested up to: 4.6.1
Stable tag: trunk
License: GPLv2 or later

Backup your Pinterest.com account by importing pins as Wordpress posts.  Supports boards, secret boards and likes.

== Description ==

Pinterest Importer allows you to connect to your Pinterest.com account; to grab all your pins (including from secret boards); and to import them as Wordpress posts.

The difference with other plugins is that it is not based on the (very limited) official Pinterest API; which also requires SSL.
This means that you can make a full backup (instead of getting only the last x pins); but it also means the plugin may broke one day or another.
Better use it quick !

* Nice GUI
* Get pins from your boards, secret boards, and likes
* Get pins from public boards by other users
* Supports both image & video pins; and set corresponding post format
* Import original HD images from pins
* Can be used on an ongoing basis : pins will not be imported several times
* Set pin creation date as post date
* Handles hashtags, which are converted to post tags
* Keep the original pin informations (pin ID, source, etc) attached to the post (stored as post metas)

= Donate! =
It truly took me a LOT of time to code this plugin.
If it saved you the time to backup manually a few hundred (or more!) pins, please consider converting this time into [a donation](http://bit.ly/gbreant).
This would be very appreciated — Thanks !

= Instruction =

This plugin requires at least php 5.3.6 with the [exif extension enabled](http://stackoverflow.com/questions/23978360/php-fatal-error-call-to-undefined-function-exif-imagetype/23978385#23978385).

1. Go to Tools -> Pinterest Importer.
2. Select "My Account" tab; and login to Pinterest
3. Select the "Boards Settings" tab and choose the boards you want to backup.
4. Enjoy !

= Contributors =
[Contributors are listed here](https://github.com/gordielachance/pinterest-importer/contributors)

= Notes =

For feature request and bug reports, [please use the forums](http://wordpress.org/support/plugin/pinterest-importer#postform).

If you are a plugin developer, [we would like to hear from you](https://github.com/gordielachance/pinterest-importer). Any contribution would be very welcome.

== Installation ==

1. Upload the plugin to your blog and Activate it.
2. Go to Tools -> Pinterest Importer.

== Frequently Asked Questions ==

= How could I change how pins are saved ? =

If you want to change how a pin is saved (for example to change its post type), you can hook actions on the filter 'pinim_before_save_pin'.

For example :

`<?php

//switch the default post type to a custom one
add_filter('pinim_before_save_pin','pin_custom_post_type');

//change post content
add_filter('pinim_before_save_pin','pin_custom_content',10,3);

function pin_custom_post_type($post){   
    $post['post_type'] = 'MY_POST_TYPE';
    return $post;
}

function pin_custom_content($post,$pin,$is_update){   
    $post['post_content'] = 'MY CONTENT';
    return $post;
}
?>`

== Screenshots ==

1. Login screen
2. Boards Settings
3. Pending Pins
4. Processed Pins
5. Plugin options


== Changelog ==

= 0.4.6 =
* Pinim_Bridge::get_user_datas() : return data from module>tree>data instead of resourceDataCache>0>data
* improved Pinim_Bridge::api_response()
* store AppVersion in session cache
* new function Pinim_Bridge::email_exists() - not used for the moment

= 0.4.5 =
* Improved errors & responses from pinim-class-bridge; plugin was crashing
* Removed the ‘me’ stuff, so force user to login with username (so we got it) instead of username or email.
= 0.4.3 =
* new function Pinim_Pin::get_post_content()
* renamed Pinim_Pin::build_post_content() to Pinim_Pin::append_medias()
* ignore pin source if does not exists (pin uploaded by user on Pinterest)
= 0.4.2 =
* two new options about post stati when importing pins.
* removed functions get_blank_post() and get_post_status(), which have been merged with Pinim_Pin::save()
* renamed the filter 'pinim_before_pin_insert' to 'pinim_before_save_pin'.
= 0.4.1 =
* New filter 'pinim_attachment_before_insert'
* Added the pin instance as argument to the 'pinim_post_before_insert' filter
* Some fixes
= 0.4.0 =
* Major release !
* Supports importing boards from other users
* Store plugin's db version with each pin
* Improved storing/getting datas
* New function Pinim_Bridge::get_board_id()
* Option to delete board preferences
* Lots of fixes
= 0.3.1 =
* Fixed bad code which was slowing down the plugin when displaying the processed pins
* better handling of the pins caching stuff
* new auto-cache option
* New 'pinim_post_before_insert' filter
* new boards views + last choice stored in session
* 'queue pins' checkbox for boards (stored in the session)
* progress bar improvements
* autoselect bulk checkbox when settings of a board are changed (jQuery)
= 0.3.0 =
* Major release !
* Improved GUI
* Lots of bug fixes
* Options page
= 0.2.8 =
* Fixed "Error getting App Version"
= 0.2.7 =
* Fixed "Error getting App Version", thanks to markamp.
= 0.2.6 =
* Fixed "Error getting App Version"
= 0.2.5 =
* fixed anonymous functions (closures) that were broken with old php versions : inherit variables from the parent scope with 'use' (http://www.php.net/manual/en/functions.anonymous.php)
= 0.2.4 =
* Improved remote image download + merged pinim_fetch_remote_image() and pinim_process_post_image() into pinim_attach_remote_image() 
* Added "updated" sortable column for pins (when have been processed)
* Fixed boards / pins sortable columns
* Fixed missing slash in pin's get_remote_url()
= 0.2.3 =
* Added support for likes
* Warning for users who don't have sessions enabled
= 0.2.2 =
* Small bugs fixes
= 0.2.1 =
* Small bugs fixes
= 0.2.0 =
* Fully rewritten !  No more needs to save / upload an HTML file.  SO COOL !
= 0.1.3 =
* Replaced http:// by https:// in pinim_get_pin_url(); pinim_get_user_url(); pinim_get_board_url(); because wp_remote_get() was returning 301.
= 0.1.2 =
* Updated plugin's readme.txt
* quoted_printable_decode() to decode MHTML
* Uploaded file needs to be MHTML to allow parsing
* Improved feedback
* Updated "a.creditItem" selector to ".creditItem a" in get_pin_board() and get_pin_source()
= 0.1.1 =
* Improved code (splitted into classes)
* Hashtags are now saved as post tags
= 0.1 ==
* First release

== TO DO ==
* Unchecked checkboxes won't save in the plugin's options (erased by default options as their values are false ?)
* use wp_update_term_count() ? seems posts count for categories is not updated.
* add source in post content should be optional
* a trashed pin should not be considered existing ?
* use some ajax functions (Pinterest queries, etc.)
* allow to fetch pins from any Pinterest board
* bug when creating 'pinim_boards_settings' : last board settings are not saved, so it is detected as new board when the page refreshes.

== Upgrade Notice ==

== Localization ==
