<?php
/*
Plugin Name: Salsa
Plugin URI: http://www.pointe730.com/salsa
Description: Facebook and Twitter Publishing for Wordpress
Author: Blake Schwendiman, Corey Brown
Version: 0.1.0
Author URI: http://thewhyandthehow.com
*/

/**
 * Facebook and Twitter Publishing for Wordpress
 * Copyright (C)2009 Pointe 730, LLC.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA. 
 */
include_once('Facebook/facebook.php');

add_action('init', 'salsa_init');
add_action('admin_init', 'salsa_admin_init');

function salsa_shared_init() {
	salsa_debug_log('installed: ' . get_option('salsa.installed_date', ''));
	if (get_option('salsa.installed_date', '') == '') {
		update_option('salsa.installed_date', date('Ymd'));
	}
	// enqueue the FacebookLoader Javascript
	wp_enqueue_script('FacebookFeatureLoader', 'http://static.ak.facebook.com/js/api_lib/v0.4/FeatureLoader.js.php', array('jquery'));

	// filter language_attributes() function, to provide fb namespace
	add_filter('language_attributes', 'salsa_language_attributes');
	add_action('admin_menu', 'salsa_plugin_menu');
}

function salsa_init() {
	salsa_shared_init();

	add_action('draft_post', 'salsa_store_post_options', 1, 2);
	add_action('publish_post', 'salsa_store_post_options', 1, 2);
	add_action('save_post', 'salsa_store_post_options', 1, 2);

  add_action('edit_form_advanced', 'salsa_post_options');
	add_action('publish_post', 'salsa_publish_post', 2);
	wp_enqueue_script('salsa-wp', salsa_js_url());
}

function salsa_admin_init() {
	salsa_shared_init();
 
  wp_enqueue_style('salsa_main', salsa_css_url());
	wp_enqueue_style('fb_connect_styles', 'http://static.ak.connect.facebook.com/connect.php/en_US/css/bookmark-button-css/connect-button-css/share-button-css/FB.Connect-css/connect-css');
  wp_enqueue_script('jQueryTools', 'http://cdn.jquerytools.org/1.1.2/jquery.tools.min.js', array('jquery'));	
}

function salsa_language_attributes($output) {
	return $output . ' xmlns:fb="http://www.facebook.com/2008/fbml"';
}

// function for printing the current URL, for fb:comments xid attribute
function get_salsa_current_url() {
	return
		'http'.($_SERVER['HTTPS'] == 'on' ? 's' : '') .
		'://' .
		$_SERVER['SERVER_NAME'] .
		($_SERVER['SERVER_PORT'] != '80' ? ':'.$_SERVER['SERVER_PORT'] : '') .
		$_SERVER['PATH_INFO'] .
		($_REQUEST['p'] ? '/?p='.$_REQUEST['p'] : '');
}

function salsa_current_url() {
	echo get_salsa_current_url();
}

function salsa_xd_receiver_url() {
	echo get_bloginfo('wpurl') . '/wp-content/plugins/salsa/xd_receiver.htm';
}

function salsa_css_url() {
	return get_bloginfo('wpurl') . '/wp-content/plugins/salsa/style.css?' . filemtime(realpath(dirname(__FILE__)).'/style.css');
}

function salsa_js_url() {
	return get_bloginfo('wpurl') . '/wp-content/plugins/salsa/salsa.js?' . filemtime(realpath(dirname(__FILE__)).'/salsa.js');
}

function get_salsa_comment_xid() {
 	if (!($permalink = get_permalink())) {
 		$permalink = get_salsa_current_url();	
 	}
 	return md5($permalink + get_option('salsa.api_key'));
}

function salsa_comment_xid() {
	echo get_salsa_comment_xid();
}

function salsa_plugin_menu() {
	add_submenu_page('options-general.php', 'Salsa', 'Salsa', 8, __FILE__, 'salsa_connect_options_form', get_bloginfo('wpurl') . '/wp-content/plugins/salsa/facebook_icon.png');
}

function salsa_fb_obj($fb_api_key, $fb_app_secret) {
	return new Facebook($fb_api_key, $fb_app_secret);
}

function salsa_fb_connected(&$fb) {
	try {
		if ($fb->api_client->session_key != '') {
			$result = $fb->api_client->users_getLoggedInUser();
		} else {
			$result = null;
		}
	} catch (Exception $e) {
		$result = null;
		salsa_debug_log('Exception in FacebookConnect::getFacebookUID: ' . $e->getMessage());
	}
    
	return $result;
}

function salsa_fb_pages(&$fb, $fb_uid) {
	$fql = 'SELECT page_id FROM page_admin WHERE uid = ' . $fb_uid;

	try {
		salsa_debug_log($fql);
		$fql_results = $fb->api_client->fql_query($fql);
		salsa_debug_log($page_ids);
		
		$page_ids = array();
		foreach($fql_results as $fql_result) {
			$page_ids[] = $fql_result['page_id'];
		}
		
		salsa_debug_log('calling baton rouge');
		$pages = implode(',', $page_ids);
		salsa_debug_log($pages);
		$result = $fb->api_client->pages_getInfo($pages, 'name, page_url, website', '', '');
		salsa_debug_log($result);
	} catch (Exception $e) {
		salsa_debug_log('Exception in FacebookConnect::getPagesForUID: ' . $e->getMessage());
		$result = array();
	}
	
	return $result;
}

function salsa_connect_options_form() {
	
	$submitted = false;
	
	if (isset($_REQUEST['nonce']) && ($nonce = $_REQUEST['nonce'])) {
		if (wp_verify_nonce($nonce, 'salsa')) {
			$submitted = true;

			update_option('salsa.twitter_notify', $_REQUEST['twitter_notify']);
			update_option('salsa.twitter_username', $_REQUEST['twitter_username']);
			update_option('salsa.twitter_password', trim($_REQUEST['twitter_password']));
			update_option('salsa.bitly_api_key', trim($_REQUEST['bitly_api_key']));
			update_option('salsa.bitly_api_login', trim($_REQUEST['bitly_api_login']));
			update_option('salsa.url_shortener_svc', trim($_REQUEST['url_shortener_svc']));
			
			update_option('salsa.facebook_notify', trim($_REQUEST['facebook_notify']));
			update_option('salsa.facebook_api_key', trim($_REQUEST['facebook_api_key']));
			update_option('salsa.facebook_app_secret', trim($_REQUEST['facebook_app_secret']));
			update_option('salsa.facebook_page', trim($_REQUEST['facebook_page']));
		}
	}
	
	switch (get_option("salsa.url_shortener_svc", 'tinyurl')) {
		case 'tinyurl':
			$cur_url_shortener_svc_id = 0;
			break;
		case 'bitly':
			$cur_url_shortener_svc_id = 1;
			break;
	}
	?>
		<script type="text/javascript">
			//<![CDATA[
			jQuery.noConflict();

			jQuery(document).ready(function() {
				jQuery("img.tooltip[title]").tooltip({
					position: "center right",
					effect: "fade",
					opacity: 0.7,
					offset: [0, 10],
					tip: '#salsa_tip'
				});
				jQuery("ul.tabs").tabs("div.panes > div", {
					initialIndex: <?php echo $cur_url_shortener_svc_id; ?>,
					onClick: function(event, tabIndex) {
						switch (tabIndex) {
							case 0:
								jQuery('#url_shortener_svc').val('tinyurl');
								break;
							case 1:
								jQuery('#url_shortener_svc').val('bitly');
								break;
						}
					}
				});
			});
		//]]>
		</script>
		
		<!-- the tooltip --> 
		<div id="salsa_tip">&nbsp;</div> 
		 
		<!-- and the triggers --> 
		<div id="demo"> 
				
				<img src="image2.jpg" title="The tooltip text #2"/> 
				<img src="image3.jpg" title="The tooltip text #3"/> 
				<img src="image4.jpg" title="The tooltip text #4"/> 
		</div>

		<form id="salsa_settings" action="admin.php?page=salsa/salsa.php" method="post">
		
			<input type="hidden" name="nonce" value="<?php echo wp_create_nonce('salsa') ?>" />
			<input type="hidden" name="url_shortener_svc" id="url_shortener_svc" value="" />
	
			<div class="wrap">
				<div id="icon-options-general" class="icon32"><br /></div>
				<h2>Salsa Options</h2>
				
				<?php if ($submitted): ?>
					<div class="updated fade" id="message" style="background-color: rgb(255, 251, 204);"><p><strong>Settings saved.</strong></p></div>
				<?php endif; ?>
				
				<fieldset style="padding: 10px; margin-top:10px; background-color: white; border: 1px solid #ccc;" >
					<h3 style="margin-top:0; padding-top:0;">Twitter Settings</h3>
					<div class="salsa_section">
						<p>
							<input type="checkbox" value="yes" name="twitter_notify" id="twitter_notify" <?php echo (get_option("salsa.twitter_notify", 'yes') == 'yes') ? 'checked="checked"' : ''; ?> />
							<label class="wide" for="twitter_notify">Notify Twitter automatically when publishing new posts.</label>
							<img class="tooltip" src="<?php echo get_bloginfo('wpurl') ?>/wp-content/plugins/salsa/info.png" title="This the the default value for Twitter notification, but you will be able to
								choose whether to notify Twitter on a post-by-post basis in the <em>Add New
								Post</em> screen." />
						</p>
						<p>
							<label for="twitter_username">Twitter Username</label>
							<input type="text" name="twitter_username" id="twitter_username" value="<?php echo get_option("salsa.twitter_username", "") ?>" />
              <img class="tooltip" src="<?php echo get_bloginfo('wpurl') ?>/wp-content/plugins/salsa/info.png" title="You must enter a valid Twitter username and password. Without them, Twitter notification
							will not work." />
						<p>
							<label for="twitter_password">Twitter Password</label>
							<input type="password" name="twitter_password" id="twitter_password" value="<?php echo get_option("salsa.twitter_password", "") ?>" />
              <img class="tooltip" src="<?php echo get_bloginfo('wpurl') ?>/wp-content/plugins/salsa/info.png" title="You must enter a valid Twitter username and password. Without them, Twitter notification
							will not work." />
						</p>
					</div>
					
					<h3>Facebook Settings</h3>
					<div class="salsa_section">
						<div id="salsa_errors_fb" class="salsa_errors" style="display: none;">
							<p class="btn_close">
								<a href="#" onclick="jQuery(this).parent().parent().hide(); return false;">Close</a>
							</p>
							<div class="salsa_errors_wrap">
								<img src="<?php echo get_bloginfo('wpurl') ?>/wp-content/plugins/salsa/error.png" class="salsa_errors_icon" /><p class="salsa_errors_message"></p>
							</div>
						</div>
						
						<?php
						  $fb_api_key = get_option("salsa.facebook_api_key", "");
						  $fb_app_secret = get_option("salsa.facebook_app_secret", "");
							
							$fb = salsa_fb_obj($fb_api_key, $fb_app_secret);
							$fb_uid = salsa_fb_connected($fb);
							if ($fb_uid) {
								$fb_pages = salsa_fb_pages($fb, $fb_uid);
							}
						?>
						
						<div id="salsa_fb_connect" <?php echo (false) ? 'style="display:none;"' : ''; ?>>
						<p>
							To get started with Facebook, you must first create an application on Facebook.
							Blah, blah, blah... <br><?php echo salsa_xd_receiver_url(); ?>
						</p>
						<p>
							<label for="facebook_api_key">Facebook API Key</label>
							<input type="text" name="facebook_api_key" id="facebook_api_key" value="<?php echo get_option("salsa.facebook_api_key", "") ?>" />
							<img class="tooltip" src="<?php echo get_bloginfo('wpurl') ?>/wp-content/plugins/salsa/info.png" title="Facebook API Key" />
						</p>
						<p>
							<label for="facebook_app_secret">Facebook App Secret</label>
							<input type="text" name="facebook_app_secret" id="facebook_app_secret" value="<?php echo get_option("salsa.facebook_app_secret", "") ?>" />
							<img class="tooltip" src="<?php echo get_bloginfo('wpurl') ?>/wp-content/plugins/salsa/info.png" title="Facebook App Secret" />
						</p>
						<p>
							<a href="#" onclick="SalsaWP.fbConnect('<?php echo salsa_xd_receiver_url(); ?>'); return false;" class="fbconnect_login_button FBConnectButton FBConnectButton_Small"> <span id="RES_ID_fb_login_text" class="FBConnectButton_Text">Connect with Facebook</span></a>
						</p>
						</div>
						
						<div id="salsa_fb_main" <?php echo (false) ? 'style="display:none;"' : ''; ?>>
						<p>
							<input type="checkbox" value="yes" name="facebook_notify" id="facebook_notify" <?php echo (get_option("salsa.facebook_notify", 'yes') == 'yes') ? 'checked="checked"' : ''; ?> />
							<label class="wide" for="facebook_notify">Notify Facebook automatically when publishing new posts.</label>
							<img class="tooltip" src="<?php echo get_bloginfo('wpurl') ?>/wp-content/plugins/salsa/info.png" title="This the the default value for Facebook notification, but you will be able to
								choose whether to notify Facebook on a post-by-post basis in the <em>Add New
								Post</em> screen." />
						</p>
						<p>
							<label for="facebook_page">Facebook Page</label>
							<select id="facebook_page" name="facebook_page">
								<?php
								  array_unshift($fb_pages, array('page_id' => $fb_uid, 'name' => 'Your Facebook wall'));
									foreach ($fb_pages as $fb_page):
									  $fb_page_sel = (get_option("salsa.facebook_page", '') == $fb_page['page_id']) ? 'selected="selected"' : '';
									?>
									  <option value="<?php echo $fb_page['page_id']; ?>" <?php echo $fb_page_sel; ?>><?php echo $fb_page['name']; ?></option>
									<?php
									endforeach;
								?>
							</select>
              <img class="tooltip" src="<?php echo get_bloginfo('wpurl') ?>/wp-content/plugins/salsa/info.png" title="Select the Facebook page you wish to publish to." />
						</p>
						<p>
							<a href="#" onclick="SalsaWP.fbPromptPermission('publish_stream'); return false;">Grant publish permissions</a>
						</p>
						</div>
						
					</div>
					
					<h3>Short URL Settings</h3>
					<div class="salsa_section">
						<p>Please select a service below and enter the appropriate information.</p>
						<!-- the tabs --> 
						<ul class="tabs"> 
							<li><a href="#"><img src="<?php echo get_bloginfo('wpurl') ?>/wp-content/plugins/salsa/tinyurl.png" /> tinyurl</a></li> 
							<li><a href="#"><img src="<?php echo get_bloginfo('wpurl') ?>/wp-content/plugins/salsa/bitly.png" /> bit.ly</a></li> 
						</ul> 
						 
						<!-- tab "panes" --> 
						<div class="panes"> 
							<div>
								<p><em>Nothing to set up here.</em></p>
							</div> 

							<div>
								<p>
								<label for="bitly_api_login">bit.ly API Login</label>
								<input type="text" name="bitly_api_login" id="bitly_api_login" value="<?php echo get_option("salsa.bitly_api_login", "") ?>" />
								</p>
								<p>
								<label for="bitly_api_key">bit.ly API Key</label>
								<input type="text" name="bitly_api_key" id="bitly_api_key" value="<?php echo get_option("salsa.bitly_api_key", "") ?>" />
								</p>
							</div> 
						</div>
					</div>

					<!--
					<div style="float: right;">
						<label><input type="radio" name="use_connect_comments" value="true" <?php if (get_option("salsa.comments_box_enabled", 'true', true) == 'true') echo 'checked="checked"' ?> /> Enabled</label>
						&nbsp; <label><input type="radio" name="use_connect_comments" value="false" <?php if (get_option("salsa.comments_box_enabled", 'true', true) == 'false') echo 'checked="checked"' ?> /> Disabled</label>
					</div>
				
					<h3 style="margin-top:0; padding-top:0;">Comments Box</h3>
					
					<p>Replace your themes' comment template with Facebook-style comments.</p>
					
					<table class="form-table">
					
						<tr>
							<th><label for="comments_api_key">Your Facebook API key</label></th>
							<td><input type="text" name="comments_api_key" id="comments_api_key" style="width:300px;" value="<?php echo get_option("salsa.comments_api_key", '') ?>" />
								&nbsp; <a href="http://developers.facebook.com/get_started.php" target="_blank">Get one now</a>
								<p style="width: 350px;"><em>Once you've created your new Facebook Application, you'll need to set the
								new application's </em>Connect URL<em> to the URL of your Wordpress installation:</em><br /><br />
								<b><?php bloginfo('home') ?></b>
								</p>
							</td>
						</tr>
						
						<tr>
							<th><label for="comments_template_title">Comments Title</label></th>
							<td><input type="text" name="comments_template_title" id="comments_template_title" value="<?php echo get_option("salsa.comments_template_title", "Comments:") ?>" /></td>
						</tr>
						
						<tr>
							<th><label for="comments_width">Width of Comment Box</label></th>
							<td><input type="text" name="comments_width" id="comments_width" value="<?php echo get_option("salsa.comments_width", '550px') ?>" /></td>
						</tr>
						
						<tr>
							<th><label for="comments_numposts">Number of Posts to Display</label></th>
							<td><input type="text" name="comments_numposts" id="comments_numposts" value="<?php echo get_option("salsa.comments_numposts", 10) ?>" /></td>
						</tr>
						
						<tr>
							<th></th>
							<td>
								<label for="comments_reverse">
									<input type="checkbox" name="comments_reverse" id="comments_reverse" value="true" <?php if (get_option("salsa.comments_reverse", 'false') == 'true') echo 'checked="checked"' ?> />
									Display Comments in Ascending Order by Date
								</label>
							</td>
						</tr>
						
						<tr>
							<th></th>
							<td>
								<label for="comments_quiet">
									<input type="checkbox" name="comments_quiet" id="comments_quiet" value="true" <?php if (get_option("salsa.comments_quiet", 'false') == 'true') echo 'checked="checked"' ?> />
									Don't display comments on commenters' Facebook walls
								</label>
							</td>
						</tr>
					
					</table>
					-->
				</fieldset>
				
				<p class="submit">
					<input class="button-primary" type="submit" value="Save Changes" name="Submit"/>
				</p>
			
			</div>
		
		</form>
	<?php
}

function salsa_post_options() {
	?>
	<div class="postbox">
		<h3>Salsa</h3>
		<div class="inside">
			<input type="checkbox" name="salsa_notify_twitter" id="salsa_notify_twitter" <?php echo (get_option("salsa.twitter_notify", 'yes') == 'yes') ? 'checked="checked"' : ''; ?> />
			<label for="salsa_notify_twitter">Update Twitter when publishing this post</label>
      <br />
			<input type="checkbox" name="salsa_notify_facebook" id="salsa_notify_facebook" <?php echo (get_option("salsa.facebook_notify", 'yes') == 'yes') ? 'checked="checked"' : ''; ?> />
			<label for="salsa_notify_facebook">Update Facebook when publishing this post</label>
		</div>
	</div>
	<?
}

function salsa_debug_log($msg) {
  return;

	$fp = fopen('/www/pointe730/logs/salsa.log', 'a');
	if ($fp) {
		if (is_array($msg) || is_object($msg)) {
			fwrite($fp, print_r($msg, true));
		} else {
			fwrite($fp, $msg . "\n");
		}
		
		fclose($fp);
	}
}

function salsa_curl($uri, $referrer, $options) {
	salsa_debug_log('salsa_curl: ' . $uri);
	$curl_handle = curl_init();
	curl_setopt($curl_handle, CURLOPT_USERAGENT, 'Salsa Plugin for WordPress');
	curl_setopt($curl_handle, CURLOPT_URL, $uri);
	curl_setopt($curl_handle, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($curl_handle, CURLOPT_TIMEOUT, 10);
	curl_setopt($curl_handle, CURLOPT_FOLLOWLOCATION, true);
	curl_setopt($curl_handle, CURLOPT_SSL_VERIFYPEER, false); 
	curl_setopt($curl_handle, CURLOPT_SSL_VERIFYHOST, false);
	curl_setopt($curl_handle, CURLOPT_FILETIME, true);
	curl_setopt($curl_handle, CURLOPT_FRESH_CONNECT, true);
	curl_setopt($curl_handle, CURLOPT_VERBOSE, false);
	curl_setopt($curl_handle, CURLOPT_CLOSEPOLICY, CURLCLOSEPOLICY_LEAST_RECENTLY_USED);
	curl_setopt($curl_handle, CURLOPT_MAXREDIRS, 5);
	curl_setopt($curl_handle, CURLOPT_HEADER, false);
	curl_setopt($curl_handle, CURLOPT_NOSIGNAL, true);
	curl_setopt($curl_handle, CURLOPT_REFERER, $referrer);
	
	foreach ($options as $name => $value) {
		curl_setopt($curl_handle, $name, $value);
	}
	
	$buffer   = curl_exec($curl_handle);
	$curlinfo = curl_getinfo($curl_handle);
	
	salsa_debug_log($buffer);
	salsa_debug_log($curlinfo);
      
	curl_close($curl_handle);
      
	if (($curlinfo['http_code'] < 400) && ($curlinfo['http_code'] != 0)) {
		return $buffer;
	} else {
		return false;
	}
	
}

function salsa_shorten_url($post_url) {
	$svc = get_option('salsa.url_shortener_svc', 'tinyurl');
	if ($svc == 'bitly') {
		$bitly_key = get_option('salsa.bitly_api_key', '');
		$bitly_login = get_option('salsa.bitly_api_login', '');
		
		if (($bitly_key != '') && ($bitly_login != '')) {
			$uri = 'http://api.bit.ly/shorten?version=2.0.1&longUrl=' . urlencode($post_url) .
			       '&login=' . urlencode($bitly_login) .
						 '&apiKey=' . urlencode($bitly_key);
			$res = salsa_curl($uri, $post_url);
			$res_obj = json_decode($res);
			salsa_debug_log($res_obj);
			
			if ($res_obj->errorCode == 0) {
				$real_res = (array) $res_obj->results;
				$short_api_obj = $real_res[$post_url];
				
				return $short_api_obj->shortUrl;
			}
		}
	}
	
	$uri = 'http://tinyurl.com/api-create.php?url=' . urlencode($post_url);
	return salsa_curl($uri, $post_url);
}

function salsa_do_notify_twitter(&$post) {
	salsa_debug_log('do_notify_twitter');
	$url = get_permalink($post->ID);
	$short_url = salsa_shorten_url($url);
	
	salsa_debug_log('url: ' . $url);
	salsa_debug_log('short_url: ' . $short_url);
	
	$twitter_username = get_option('salsa.twitter_username', '');
	$twitter_password = get_option('salsa.twitter_password', '');
	
	salsa_debug_log($twitter_username . ':' . $twitter_password);
	if (($twitter_username != '') && ($twitter_password != '')) {
		$status = $post->post_title . ' ' . $short_url;
		$post_fields  = array('status' => $status);
		$curl_options = array(CURLOPT_POST => 1,
													CURLOPT_HTTPHEADER => array('Expect:'),
													CURLOPT_POSTFIELDS => $post_fields,
													CURLOPT_USERPWD => $twitter_username . ':' . $twitter_password);

    salsa_curl('https://twitter.com/statuses/update.json', $url, $curl_options);		
	}
	add_post_meta($post->ID, '_salsa_twitter_notified', date('Ymd'));
}

function salsa_find_imgs($post_body) {
	if (preg_match_all('|<img.*?src=\"([^\"]*?)\"|msi', $post_body, $matches)) {
		salsa_debug_log("MATCH");
		return $matches[1];
	}
	salsa_debug_log("NO MATCH");
	return array();
}

function salsa_do_notify_facebook(&$post) {
	salsa_debug_log('do_notify_facebook');
	
	$url = get_permalink($post->ID);

	$fb_api_key = get_option("salsa.facebook_api_key", "");
	$fb_app_secret = get_option("salsa.facebook_app_secret", "");

	$fb = salsa_fb_obj($fb_api_key, $fb_app_secret);
	$fb_uid = salsa_fb_connected($fb);
	$fb_page_id = get_option('salsa.facebook_page', $fb_uid);
	
	$media = null;
	$imgs = salsa_find_imgs($post->post_content);
	if (count($imgs) > 0) {
		$media = array(array('type' => 'image',
												  'src' => $imgs[0],
													'href' => $url));
	}

  $message = $post->post_title . ' - ' . $url;
	$attachment = array('name' => $post->post_title,
											'href' => $url,
											'caption' => 'www.xxxxxxx.com',
											'description' => 'xxxxxxx',
											'media' => $media,
											);
	$action_links = array( array('text' => 'Share',
															 'href' => 'http://www.facebook.com/share.php?u=' . urlencode($url) ));
	$attachment = json_encode($attachment);
	$action_links = json_encode($action_links);
	//$action_links = null;
	
	if ($fb_uid == $fb_page_id) {
  	$result = $fb->api_client->stream_publish($message, $attachment, $action_links);
	} else {
  	$result = $fb->api_client->stream_publish($message, $attachment, $action_links, null, $fb_page_id);
	}
	
	salsa_debug_log('result: ' . $result);
	add_post_meta($post->ID, '_salsa_facebook_notified', date('Ymd'));
}

function salsa_publish_post($post_id) {
	salsa_debug_log('salsa_publish_post');
	$post = get_post($post_id);
	$notify_twitter = get_post_meta($post_id, '_salsa_notify_twitter', true);
	$notify_facebook = get_post_meta($post_id, '_salsa_notify_facebook', true);
	$url = get_permalink($post_id);
	salsa_debug_log($post);
	salsa_debug_log($notify_twitter);
	salsa_debug_log($notify_facebook);
	salsa_debug_log('perm: ' . $url);
	$twitter_notified = get_post_meta($post_id, '_salsa_twitter_notified', true);
	$facebook_notified = get_post_meta($post_id, '_salsa_facebook_notified', true);
	salsa_debug_log('salsa_twitter_notified: ' . $twitter_notified);
	salsa_debug_log('salsa_facebook_notified: ' . $facebook_notified);
	
	$installed_date = get_option('salsa.installed_date', date('Y-m-d'));
	$post_date = date('Ymd', strtotime($post->post_date));
	salsa_debug_log('post_date: ' . $post_date);
	
	if (($notify_twitter == 'yes') && ($twitter_notified == '')) {
		if ($post_date >= $installed_date) {
			salsa_do_notify_twitter($post);
		}
	}
	if (($notify_facebook == 'yes') && ($facebook_notified == '')) {
		if ($post_date >= $installed_date) {
			salsa_do_notify_facebook($post);
		}
	}
}

function salsa_store_post_options($post_id, $post = false) {
	salsa_debug_log("salsa_store_post_options");
	salsa_debug_log($post_id);
	salsa_debug_log($_POST);
	
	if (count($_POST) == 0) {
		return;
	}
	
	$notify_twitter = isset($_POST['salsa_notify_twitter']) ? 'yes' : 'no';
	$notify_facebook = isset($_POST['salsa_notify_facebook']) ? 'yes' : 'no';

	if (!update_post_meta($post_id, '_salsa_notify_twitter', $notify_twitter)) {
		add_post_meta($post_id, '_salsa_notify_twitter', $notify_twitter);
	}
	if (!update_post_meta($post_id, '_salsa_notify_facebook', $notify_facebook)) {
		add_post_meta($post_id, '_salsa_notify_facebook', $notify_facebook);
	}
}