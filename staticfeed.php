<?php
/*
Plugin Name: Static Feed (patched by webgilde)
Plugin URI: http://www.pluginspodcast.com/plugins/staticfeed/
Description: Improves the performance of your site by serving your feeds as static (XML) files.
Author: Angelo Mandato, patched by Thomas Maier, webgilde for latest WP version
Version: 2.0
Author URI: http://www.pluginspodcast.com/
*/ 

define('STATICFEED_VERSION', '2.0');
$GLOBALS['g_staticfeed_disable'] = false;

// Set these settings in your wp-config.php if you want to override them. All three of these settings apply to the Permalinks option
if( !defined('STATICFEED_URL') )
	define('STATICFEED_URL', WP_CONTENT_URL . '/staticfeed');
if( !defined('STATICFEED_DIR') )
	define('STATICFEED_DIR', WP_CONTENT_DIR . '/staticfeed');
if( !defined('STATICFEED_DEFAULT_CONTENT_TYPE') ) // Set to false if you don't want Static Feed to add the content type, or set to specific content type to use a custom one
	define('STATICFEED_DEFAULT_CONTENT_TYPE', 'application/rss+xml');

// Actions where we need to refresh the static feeds
function staticfeed_edit_post($not_used)
{
	if( !empty($GLOBALS['g_staticfeed_refreshed']) )
		staticfeed_refresh_all();
	$GLOBALS['g_staticfeed_refreshed'] = true;
}

add_action('publish_post', 'staticfeed_edit_post');
add_action('edit_post', 'staticfeed_edit_post');
add_action('delete_post', 'staticfeed_edit_post');
add_action('publish_phone', 'staticfeed_edit_post');

// Change the link URL to the static version
function staticfeed_feed_link($output, $feed)
{
	if( $GLOBALS['g_staticfeed_disable'] )
		return $output;
	
	$Settings = get_option('staticfeed_general');
	if( $Settings == false )
		return $output;
	
	if( !empty($Settings['permalinks']) )
		return $output;

	// Make sure we don't handle the rss2 comments feed by accident
	if (!$feed && false != strpos($output, '/comments/')) {
	    $feed = 'comments_rss2';
	} else if (!$feed) {
		$feed = 'rss2';
	}

	// Lets swap in our FEED
	if( !empty($Settings[$feed]['enable']) && strlen($Settings[$feed]['url']))
	  $output = $Settings[$feed]['url'];
	return $output;
}

add_filter('feed_link', 'staticfeed_feed_link', 10, 2);

// Save settings here
function staticfeed_admin_init()
{
	global $wp_rewrite;
	
	if( !empty($_POST['action']) && $_POST['action'] == 'staticfeed_save' )
	{
		check_admin_referer('update-staticfeed');
		$Settings = staticfeed_stripslashes( $_POST['Settings']);
		
		
		if( !empty($Settings['permalinks']) )
		{
			// Make sure the staticfeed directory exists...
			$success = true;
			if( !is_dir(STATICFEED_DIR) )
				$success = @mkdir(STATICFEED_DIR, 0777);
			
			if( $success )
			{
				// Make all the static files...
				while( list($feed_slug, $feed_settings) = each($Settings) )
				{
					if( !is_array($feed_settings) )
						continue; // Skip this, it's a setting
					$local_file = str_replace('\\', '/', STATICFEED_DIR) .'/'. $feed_slug .'.xml';
					// $Settings[ $feed_slug ]['file'] = $local_file;
					
					if( !file_exists($local_file) )
					{
						if( !@touch($local_file) )
							$GLOBALS['g_staticfeed_error'] .= sprintf(__('Unable to create file %s for feed %s.'), $local_file, staticfeed_readable_name($feed_slug) );
						else
							chmod($local_file, 0666);
					}
					else if( !is_writable($local_file) )
					{
						$GLOBALS['g_staticfeed_error'] .= sprintf(__('Unable to write to file %s for feed %s.'), $local_file, staticfeed_readable_name($feed_slug) );
					}
				}
				reset($Settings);
			}
			else
			{
				$GLOBALS['g_staticfeed_error'] = sprintf(__('Unable to create directory %s.'), STATICFEED_DIR);
			}
		}
		
		// Make sure all the checkbox values are set to something...
		$OldSettings = get_option('staticfeed_general');
		$feed_types = staticfeed_get_feed_types();
		if( $OldSettings )
		{
			while( list($feed_slug,$feed_name) = each($feed_types) )
			{
				if( !isset($Settings[ $feed_slug ]['enabled']) )
					$Settings[$feed_slug]['enabled'] = 0;
				if( !isset($Settings[ $feed_slug ]['url']) && isset($OldSettings[ $feed_slug ]['url']) )
					$Settings[$feed_slug]['url'] = $OldSettings[ $feed_slug ]['url'];
				if( !isset($Settings[ $feed_slug ]['file']) && isset($OldSettings[ $feed_slug ]['file']) )
					$Settings[$feed_slug]['file'] = $OldSettings[ $feed_slug ]['file'];
			}
			reset($feed_types);
			
			//if( !isset($Settings['permalinks']) )
			//	$Settings['permalinks'] = 0;
		}
			
		update_option('staticfeed_general', $Settings);
		$GLOBALS['g_staticfeed_status'] = 'Static Feed settings saved.';
		if( !empty($_POST['info_update_and_refresh']) )
		{
			$results = staticfeed_refresh_all($Settings);
			if( $results )
			$GLOBALS['g_staticfeed_status'] .= '<br />'.$results;
		}
		
		// Update the .htaccess file (if required)
		if( !empty($Settings['permalinks']) )
		{
			require_once(ABSPATH . 'wp-admin/includes/admin.php');
			$home_path = get_home_path();
			$htaccess_writable = false;
			if( empty($Settings['update_htaccess_manually']) )
			{
				if( ( !file_exists($home_path . '.htaccess') && is_writable($home_path) ) || is_writable($home_path . '.htaccess') )
					$htaccess_writable = true;
			}
			
			if( $htaccess_writable && function_exists('save_mod_rewrite_rules') )
			{
				// Rewrite the rules and the .htaccess file
				delete_option('rewrite_rules');
				$wp_rewrite->wp_rewrite_rules();
				save_mod_rewrite_rules();
				$GLOBALS['g_staticfeed_status'] .= '<br />';
				$GLOBALS['g_staticfeed_status'] .= __('Permalink structure and .htaccess updated.');
			}
			else
			{
				// Let the user know they need to update their .htaccess file
				$GLOBALS['g_staticfeed_htaccess'] = true;
			}
		}
	}
}

add_action('init', 'staticfeed_admin_init');

// Admin settings page
function staticfeed_admin_page()
{
	global $wp_rewrite;

	// Hepful info: http://codex.wordpress.org/WordPress_Feeds
	$default_feed = get_default_feed();
	$feed_types = staticfeed_get_feed_types();
	$Settings = get_option('staticfeed_general');
	
	if( !ini_get( 'allow_url_fopen' ) && !function_exists( 'curl_init' ) )
	{
		if( !empty($GLOBALS['g_staticfeed_error']) )
			$GLOBALS['g_staticfeed_error'] .= '<br />';
		$GLOBALS['g_staticfeed_error'] .= __('Your server must have either the php.ini setting \'allow_url_fopen\' enabled or the PHP cURL library installed to use this plugin.');
	}
	
?>
<script language="javascript" type="text/javascript">

var g_staticfeed_root_url = '<?php echo get_option('siteurl'); ?>/';
var g_staticfeed_root_dir = '<?php echo str_replace('\\', '/',ABSPATH); ?>';

jQuery(document).ready(function($) {
	jQuery('.staticfeed_cb').change(function () {
		var FeedSlug = jQuery(this).attr('id').substring( jQuery(this).attr('id').lastIndexOf('_')+1 );
		if( jQuery(this).attr("checked") == true ) {
			jQuery('#staticfeed_settings_'+ FeedSlug).css("display", 'block' );
		}
		else {
			jQuery('#staticfeed_settings_'+ FeedSlug).css("display", 'none' );
		}
	} );
	
	jQuery('.staticfeed-url').change(function () {
		var FeedSlug = jQuery(this).attr('id').substring( jQuery(this).attr('id').lastIndexOf('_')+1 );
		var value = jQuery(this).val();
		if( value.indexOf(g_staticfeed_root_url) == 0 )
		{
			var path = jQuery('#staticfeed_file_'+FeedSlug).val();
			if( path == '' || path.indexOf( g_staticfeed_root_dir ) == 0 )
			{
				var relative_path = value.substring( g_staticfeed_root_url.length );
				jQuery('#staticfeed_file_'+FeedSlug).val( g_staticfeed_root_dir + relative_path );
			}
		}
	} );
	jQuery('.staticfeed-file').change(function () {
		var FeedSlug = jQuery(this).attr('id').substring( jQuery(this).attr('id').lastIndexOf('_')+1 );
		var value = jQuery(this).val();
		value.replace('\\', '/');
		if( value.indexOf(g_staticfeed_root_dir) == 0 )
		{
			var path = jQuery('#staticfeed_url_'+FeedSlug).val();
			if( path == '' || path.indexOf( g_staticfeed_root_url ) == 0 )
			{
				var relative_path = value.substring( g_staticfeed_root_dir.length );
				jQuery('#staticfeed_url_'+FeedSlug).val( g_staticfeed_root_url + relative_path );
			}
		}
	} );
} );

</script>
<div class="wrap">
  <h2><?php echo __('Static Feed'); ?></h2>
<?php
	if( !empty($GLOBALS['g_staticfeed_status']) )
	{
		echo "<div class=\"updated\" style=\"margin: 10px; line-height: 26px; font-size: 12px; border-width: 1px; border-style: solid; \">";
		echo $GLOBALS['g_staticfeed_status'];
		echo "</div>\n";
	}
	
	if( !empty($GLOBALS['g_staticfeed_error']) )
	{
		echo "<div class=\"error\" style=\"margin: 10px; line-height: 26px; font-size: 12px; border-width: 1px; border-style: solid; font-weight: bold; \">";
		echo $GLOBALS['g_staticfeed_error'];
		echo "</div>\n";
	}
?>

<?php if ( !empty($GLOBALS['g_staticfeed_htaccess']) ) { ?>
<div style="background-color: #ffebe8; border: 1px solid #c00; padding: 10px;" class="error2">
	<h3 style="margin: 0;"><?php echo __('Please Update Your .htaccess Now'); ?></h3>
	<?php if( empty($Settings['update_htaccess_manually']) ) { ?>
	<p><?php echo __('If your <code>.htaccess</code> file were <a href="http://codex.wordpress.org/Changing_File_Permissions" target="_blank">writable</a>, we could do this automatically, but it isn&#8217;t so these are the mod_rewrite rules you should have in your <code>.htaccess</code> file. Click in the field and press <kbd>CTRL + A</kbd> to select all.') ?></p>
	<?php } else { ?>
	<p><?php echo __('These are the mod_rewrite rules you should have in your <code>.htaccess</code> file. Click in the field and press <kbd>CTRL + A</kbd> to select all.') ?></p>
	<?php } ?>
	<form method="post">
	<?php wp_nonce_field('update-staticfeed'); ?>
		<p style="margin: 0;"><textarea rows="6" class="large-text readonly" name="rules" id="rules" readonly="readonly" style="background-color: #ECECEC;"><?php echo esc_html($wp_rewrite->mod_rewrite_rules()); ?></textarea></p>
		<p id="staticfeed_rewrite_rules_link" style="text-align: right; margin: 0 10px;"><a href="javascript:void()" onclick="jQuery('#staticfeed_rewrite_rules_link').css('display','none');jQuery('#staticfeed_rewrite_rules').css('display','block');"><?php echo __('View only the rewrite rules added by Static Feed'); ?></a>
		<p id="staticfeed_rewrite_rules" style="display:none;">
		<?php echo sprintf(__('Specific rules added by Static Feed. These rules are placed immediately below the "RewriteBase %s" line.'), staticfeed_get_home_root() ); ?>
			<textarea rows="6" class="large-text readonly" name="staticfeed_rules" id="staticfeed_rules" readonly="readonly" style="background-color: #ECECEC;"><?php echo esc_html( staticfeed_get_rewrite_rules() ); ?></textarea>
		</p>
	</form>
</div>
<?php } ?>

<form method="post">
 <?php wp_nonce_field('update-staticfeed'); ?>
<p style="margin-bottom: 0;"><?php echo __('Select the Feeds to be served statically.'); ?></p>
<?php
	while( list($feed_slug,$feed_name) = each($feed_types) )
	{
		$display_options = '';
		if( empty($Settings[ $feed_slug ]['enable']) )
			$display_options = ' style="display:none;"';
?>
<table class="form-table">
	<tr>
		<th>
			<input type="checkbox" name="Settings[<?php echo $feed_slug; ?>][enable]" id="staticfeed_enable_<?php echo $feed_slug; ?>" class="staticfeed_cb" value="1" <?php if( empty($display_options) ) echo 'checked'; ?> />
			<label for="staticfeed_enable_<?php echo $feed_slug; ?>"><strong><?php echo htmlspecialchars($feed_name); ?></strong></label>
		</th>
		<td>
			<p style="margin: 0;">
				<a href="<?php echo get_feed_link($feed_slug); ?>" target="_blank"><?php echo get_feed_link($feed_slug); ?></a>
				| <a href="http://www.feedvalidator.org/check.cgi?url=<?php echo urlencode(get_feed_link($feed_slug)); ?>" title="<?php echo __('Validate'); ?>" target="_blank"><?php echo __('Validate'); ?></a></p>
		</td>
	</tr>
</table>
<div id="staticfeed_settings_<?php echo $feed_slug; ?>" <?php echo $display_options; ?>>
<table class="form-table">
<?php 
		if( empty($Settings['permalinks']) )
		{ 
?>
<tr>
	<th style="text-align: right;"><label for="staticfeed_url_<?php echo $feed_slug; ?>"><?php echo __('Custom Static URL:'); ?></label></th>
	<td>
		<input name="Settings[<?php echo $feed_slug; ?>][url]" id="staticfeed_url_<?php echo $feed_slug; ?>" value="<?php if( !empty($Settings[ $feed_slug ]['url']) ) echo htmlspecialchars($Settings[ $feed_slug ]['url']); ?>" class="regular-text staticfeed-url" type="text" style="width: 70%;"><br />
	</td>
</tr>
<tr>
	<th style="text-align: right;"><label for="staticfeed_file_<?php echo $feed_slug; ?>"><?php echo __('Local File Path:'); ?></label></th>
	<td>
		<input name="Settings[<?php echo $feed_slug; ?>][file]" id="staticfeed_file_<?php echo $feed_slug; ?>" value="<?php if( !empty($Settings[ $feed_slug ]['file']) ) echo htmlspecialchars($Settings[ $feed_slug ]['file']); ?>" class="regular-text staticfeed-file" type="text" style="width: 70%;" <?php if( !empty($Settings['permalinks']) ) echo 'disabled' ?>>
<?php 
		if( empty($Settings['permalinks']) )
		{ 
			$url_suggest = get_option('siteurl') ."/{$feed_slug}.xml";
			$file_suggest = str_replace("\\", "/", ABSPATH ."{$feed_slug}.xml" );
?>
		<br /><a id="staticfeed_example_<?php echo $feed_slug; ?>_link" href="javascript:void();" onclick="jQuery('#staticfeed_example_<?php echo $feed_slug; ?>_link').css('display','none');jQuery('#staticfeed_example_<?php echo $feed_slug; ?>').css('display','block');"><?php echo __('Show Example'); ?></a>
		<p id="staticfeed_example_<?php echo $feed_slug; ?>" style="display: none;">
		<?php echo __('Example URL:'); ?> <?php echo $url_suggest; ?> 
		<br />
		<?php echo __('Example File:'); ?> <?php echo $file_suggest; ?>
		<br />
		<a href="#" onclick="jQuery('#staticfeed_url_<?php echo $feed_slug; ?>').val('<?php echo $url_suggest; ?>');jQuery('#staticfeed_file_<?php echo $feed_slug; ?>').val('<?php echo $file_suggest; ?>');return false;"><?php echo __('Use Example Above'); ?></a>
		</p>
<?php
		}
?>
	</td>
</tr>
<?php } ?>
<?php 
		if( !empty($Settings['permalinks']) )
		{ 
?>
<tr>
	<th style="text-align: right;"><label><?php echo __('Bypass URL:'); ?></label></th>
	<td>
		<p style="margin: 0;"><a href="<?php echo get_feed_link($feed_slug).(strstr( get_feed_link($feed_slug), '?')?'&staticfeed=no':'?staticfeed=no'); ?>" target="_blank"><?php echo get_feed_link($feed_slug).(strstr( get_feed_link($feed_slug), '?')?'&staticfeed=no':'?staticfeed=no'); ?></a></p>
		<?php echo __('Use this link to bypass the static feed.'); ?>
	</td>
</tr>
<?php
		}
?>
</table>
</div>
<?php
	}
	
	if( empty($Settings['permalinks']) )
	{
?>
	<p><?php echo __('Note: The web server must have write access to the specified local files. 
	If you encounter errors, please create an empty file saved as the 	feeds filename and 
	upload it to the apporpriate location on your server.  Then change your file permissions to 
	the file so the web server (typically Apache) can write to it.  This can be achieved by 
	setting the file permissions to 660.  At a worst case, you can set the permissions to 666.'); ?></p>
<?php
	}
?>

	<h2><?php echo __('Additional Settings'); ?></h2>
	<table class="form-table">
		<tr>
			<th> <label for="staticfeedsettings_permalinks"><?php echo __('Permalinks'); ?></label></th>
			<td><p style="margin: 0 0 0 0;">
			<input type="checkbox" name="Settings[permalinks]" id="staticfeedsettings_permalinks" value="1" <?php if( !empty($Settings['permalinks']) ) echo 'checked'; ?> />
			<?php echo __('Rewrite Permalink Feed URLs (e.g. example.com/feed/) to Static Feeds.'); ?>
			</p>
			(<?php echo __('requires write access to your .htaccess file'); ?>)
			<p>
				<?php echo sprintf(__('WARNING: If you have custom rules entered into your .htaccess file, make sure you check the %s option below so this plugin does not overwrite them.'), '<a href="javascript:void();" onclick="jQuery(\'#staticfeedsettings_update_htaccess_manually\').attr(\'checked\',\'true\');">Manually Update .htaccess</a>'); ?>
			</p>
			</td>
		</tr>
		<tr>
			<th> <label for="staticfeedsettings_update_htaccess_manually"><?php echo __('Manually Update .htaccess'); ?></label></th>
			<td><p style="margin: 0 0 0 0;">
			<input type="checkbox" name="Settings[update_htaccess_manually]" id="staticfeedsettings_update_htaccess_manually" value="1" <?php if( !empty($Settings['update_htaccess_manually']) ) echo 'checked'; ?> />
			<?php echo __('Static Feed will not automatically update your .htaccess file.'); ?>
			</p>
			(<?php echo __('the changes required to your .htaccess file will be displayed upon saving'); ?>)
			</td>
		</tr>
	</table>
	
  <div class="submit">
		<input type="hidden" name="action" value="staticfeed_save" />
		<input type="submit" name="info_update" value="<?php echo __('Save'); ?>" />
		<input type="submit" name="info_update_and_refresh" value="<?php echo __('Save and Refresh Static Feeds'); ?>" />
	</div>
	
	<p style="font-size: 85%; text-align: center;">
		<a href="http://www.pluginspodcast.com/plugins/staticfeed/" title="<?php echo __('Static Feed'); ?>" target="_blank"><?php echo __('Static Feed'); ?></a> <?php echo __('version'); ?> <?php echo STATICFEED_VERSION; ?>
		<?php echo __('by'); ?> <a href="http://www.pluginspodcast.com/" target="_blank" title="<?php echo __('Plugins, The WordPress Plugins Podcast'); ?>"><?php echo __('Plugins, The WordPress Plugins Podcast'); ?></a> &#8212; <a href="http://twitter.com/PluginsPodcast" target="_blank" title="<?php echo __('Follow us on Twitter'); ?>"><?php echo __('Follow us on Twitter'); ?></a>
	</p>
 </form>
</div>
		<?php
}

// Add static feed to the WordPress menu
function staticfeed_admin()
{
	add_options_page( __('Static Feed'), __('Static Feed'), 'manage_options', basename(__FILE__), 'staticfeed_admin_page');
}

add_action('admin_menu','staticfeed_admin',1);

// Returns an associative array of feed types in this blog, key is the feed slug, value is a readable name.
function staticfeed_get_feed_types()
{
	global $wp_rewrite, $g_staticfeed_types;
	if( !empty($GLOBALS['g_staticfeed_types']) )
		return $GLOBALS['g_staticfeed_types'];
	$default_feed = get_default_feed();
	
	$feed_types = array();
	while( list($null,$value) = each($wp_rewrite->feeds) )
	{
		if( $value == 'feed' )
			continue; // Skip the default feed as it will be mapped to one of the 4 types
		
		$name = staticfeed_readable_name($value);
		if( $value == $default_feed )
			$name = __('Default') .' '. $name;
		$name .= ' '. __('Feed');

		// Make sure the default feed is always at the top of the list:
		if( $value == $default_feed )
			$feed_types = array_merge( array($value=>$name), $feed_types );
		else
			$feed_types[ $value ] = $name;
	}
	reset($wp_rewrite->feeds);
	$GLOBALS['g_staticfeed_types'] = $feed_types;
	return $feed_types;
}

// Handle rewrite rules
function staticfeed_mod_rewrite_rules($rules)
{
	$Settings = get_option('staticfeed_general');
	if( $Settings == false )
		return $rules;
	
	if( empty($Settings['permalinks']) )
		return $rules;
	
	$home_root = staticfeed_get_home_root();
	
	// Okay, time to rewrite some rules...
	$new_rules = staticfeed_get_rewrite_rules($home_root);
	if( $new_rules )
	{
		$rules = str_replace("RewriteBase $home_root\n", "RewriteBase $home_root\n$new_rules", $rules);
	}
		
	return $rules;
}

add_filter('mod_rewrite_rules', 'staticfeed_mod_rewrite_rules', 1000);

/* Helper Functions */
function staticfeed_stripslashes($data)
{
	if( !$data )
		return $data;
	
	if( !is_array($data) )
		return stripslashes($data);
	
	while( list($key,$value) = each($data) )
	{
		if( is_array($value) )
			$data[$key] = staticfeed_stripslashes($value);
		else
			$data[$key] = stripslashes($value);
	}
	reset($data);
	return $data;
}

function staticfeed_refresh_all($Settings=false)
{
	if( $Settings == false )
		$Settings = get_option('staticfeed_general');
	
	if( !$Settings )
		return __('Error, no Static Feed settings found.');
	
	$return = ''; // Lets loop through
	while( list($feed_slug,$feed_settings) = each($Settings) )
	{
		if( !is_array($feed_settings) )
			continue; // This is just a setting for Static Feed plugin, it's not an actual feed...
		
		if( !empty($feed_settings['enable']) )
		{
			if( !empty($return) )
				$return .= '<br />';
			
			$feed_name = staticfeed_readable_name($feed_slug);
			$feed_url = staticfeed_feed_link_orig($feed_slug);
			if( !empty($Settings['permalinks']) ) // If we are working with permalinks we need to by-pass the Rewrite handling
				$feed_url .= (strstr($feed_url, '?')?'&staticfeed=no':'?staticfeed=no');
			$content = staticfeed_get_feed_content($feed_url);
			if( $content['success'] )
			{
				// If we are rewriting the feed to a new URL, then lets swap the value in the content...
				if( empty($Settings['permalinks']) )
					$content['content'] = str_replace($feed_url, $feed_settings['url'], $content['content']);
				else
					$content['content'] = str_replace($feed_url, staticfeed_feed_link_orig($feed_slug), $content['content']);
				
				$local_file = str_replace('\\', '/', STATICFEED_DIR) .'/'. $feed_slug .'.xml';
				if( empty($Settings['permalinks']) )
					$local_file = $feed_settings['file'];
				
				$saved_status = staticfeed_save_feed_content( $local_file, $content['content']);
				if( $saved_status['success'] )
				{
					$return .= ' &nbsp; &nbsp; '. sprintf(__('%s feed (%s) refreshed successfully.'), $feed_name, '<a href="'. get_feed_link($feed_slug) .'" target="_blank">'. get_feed_link($feed_slug) .'</a>');
				}
				else
				{
					$return .= ' &nbsp; &nbsp; '. sprintf(__('Error with %s feed:'), $feed_name) .' '. $saved_status['message'];
				}
			}
			else
			{
				$return .= ' &nbsp; &nbsp; '. sprintf(__('Error with %s feed:'), $feed_name) .' '. $content['message'];
			}
		}
	}
	reset($Settings);
	
	return $return;
}

function staticfeed_get_feed_content($feed_url)
{
	if( function_exists('wp_remote_get') ) // Lets us specify the user agent and get a real error message if failure
	{
		$options = array();
		$options['user-agent'] = 'StaticFeed WordPress Plugin/';
		$response = wp_remote_get( $feed_url, $options );
		
		if ( is_wp_error( $response ) )
			return array('success'=>false, 'message'=>$response->get_error_message() );
		return array('success'=>true, 'content'=>$response['body']);
	}
	
	$content = wp_remote_fopen($feed_url);
	if( $content === false )
		return array('success'=>false, 'message'=>__('Unable to download dynamic feed content.') );
	return array('success'=>true, 'content'=>$content);
}

// Get the root directory of web site
function staticfeed_get_home_root()
{
	$home_root = parse_url(get_option('home'));
	if( isset( $home_root['path'] ) )
		return trailingslashit($home_root['path']);
	return '/';
}

// Generates the ModRewrite rules for inclusion in the .htaccess file
function staticfeed_get_rewrite_rules($home_root=false)
{
	global $wp_rewrite;
	
	$Settings = get_option('staticfeed_general');
	if( $Settings == false )
		return '';
	
	if( empty($Settings['permalinks']) )
		return '';
		
	if( $home_root === false )
		$home_root = staticfeed_get_home_root();
	
	$permalink_structure = $wp_rewrite->get_feed_permastruct();
	$rules = '';
	/* Loop through static feeds here: */
	while( list($feed_slug,$feed_settings) = each($Settings) )
	{
		if( !is_array($feed_settings) )
			continue; // This is just a setting for Static Feed plugin, it's not an actual feed...
		
		if( empty($feed_settings['enable']) )
			continue;

		if ( get_default_feed() == $feed_slug )
			$permalink = str_replace('%feed%', '', $permalink_structure);
		else
			$permalink = str_replace('%feed%', $feed_slug, $permalink_structure);
		$permalink = preg_replace('#/+#', '/', $permalink);
		$permalink = trim($permalink, '/') . '/'; // Make sure the permalink doesn't start with a slash but ends with one...
		
		$local_file = str_replace('\\', '/', STATICFEED_DIR) .'/'. $feed_slug .'.xml';
		
		$static_local_path = ltrim(substr($local_file, strlen(WP_CONTENT_DIR) ), '/'); // Returns staticfeed/file.xml
		$static_web_url = rtrim(WP_CONTENT_URL, '/') .'/'. $static_local_path;
		$static_web_parts = parse_url($static_web_url);
		$static_web_path = $static_web_parts['path'];
		
		$rewrite_content_type = '';
		if( STATICFEED_DEFAULT_CONTENT_TYPE )
		{
			switch( $feed_slug )
			{
				case 'atom': $rewrite_content_type .= ',T=application/atom+xml'; break;
				case 'rdf': $rewrite_content_type .= ',T=application/rdf+xml'; break;
				default: $rewrite_content_type .= ',T='. STATICFEED_DEFAULT_CONTENT_TYPE;
			}
		}
		
		$rules .= "# Rewrite $permalink to Static Feed:\n";
		$rules .= "RewriteRule ^{$permalink}?$ {$static_web_path} [L{$rewrite_content_type}]\n";
	}
	
	if( $rules )
	{
		$rules_prefix = "RewriteCond %{QUERY_STRING} staticfeed=no\n";
		$rules_prefix .= "RewriteCond %{REQUEST_FILENAME} !-f\n";
		$rules_prefix .= "RewriteCond %{REQUEST_FILENAME} !-d\n";
		$rules_prefix .= "RewriteRule . {$home_root}{$wp_rewrite->index} [L]\n";
		return $rules_prefix . $rules;
	}
	return '';
}

// Writes the feed content to a file with error reporting on failure
function staticfeed_save_feed_content($local_file, $content)
{
	if( !file_exists($local_file) )
	{
		if( !@touch($local_file) )
			return array('success'=>false, 'message'=>__('Unable to create file.') );
		chmod($local_file, 0666);
	}
	
	if( !is_writable($local_file) )
		return array('success'=>false, 'message'=>__('Unable to write to file.') );
	
	$fp = fopen($local_file, 'w');
	if( $fp === false )
		return array('success'=>false, 'message'=>__('Unable to open to file.') );
	
	if( fwrite($fp, $content) === false )
	{
		fclose($fp);
		return array('success'=>false, 'message'=>__('Unable to put content into file.') );
	}
	fclose($fp);
	
	return array('success'=>true);
}

// Returns the WordPress original permalink
function staticfeed_feed_link_orig($feed_slug)
{
	$GLOBALS['g_staticfeed_disable'] = true;
	$feed_url = get_feed_link($feed_slug);
	$GLOBALS['g_staticfeed_disable'] = false;
	return $feed_url;
}

// Returns a readable name of the feed slug
function staticfeed_readable_name($name)
{
	$name = str_replace('-', ' ', $name);
	$name = str_replace('rss', 'RSS', $name);
	$name = str_replace('rdf', 'RDF', $name);
	$name = str_replace('rdf', 'RDF', $name);
	return ucwords($name);
}

?>