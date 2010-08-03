<?php
if ( !defined('MEDIAWIKI' ) ) die( "Not an entry point." );
/**
 * WikiaAdmin extension - allows management of multiple wikis from the
 *                        same master DB and LocalSettings file
 * 
 * @package MediaWiki
 * @subpackage Extensions
 * @author Aran Dunkley [http://www.organicdesign.co.nz/nad User:Nad]
 * @copyright © 2007-2010 Aran Dunkley
 * @licence GNU General Public Licence 2.0 or later
 * 
 * Version 2.0 started on 2010-06-24
 */
define( 'WIKIAADMIN_VERSION', "2.0.9, 2010-08-03" );

# WikiaAdmin uses $wgWikiaSettingsDir/wgDBname to store the LocalSettings for
# the wikis in this DB. It reads in the settings files and determines
# which settings file to apply based on the domain of the request.
if ( !isset( $wgWikiaSettingsDir ) ) die( "\$wgWikiaSettingsDir is not set!" );
if ( !is_dir( $wgWikiaSettingsDir ) ) die( "The \$wgWikiaSettingsDir (\"$wgWikiaSettingsDir\") doesn't exist!" );
if ( !is_writable( $wgWikiaSettingsDir ) ) die( "Unable to write to the \$wgWikiaSettingsDir directory!" );

# Set this if only specific domains are allowed
$wgWikiaAdminDomains = false;

# Array of subdomains which are implied by naked domain usage
$wgWikiaImpliedSubdomains = array( 'www', 'wiki' );

# This must contain at least one database dump in the form of description => file
$wgWikiaDatabaseDumps = array();

# The settings for all wikis under this master wiki are stored in
# a persistent array which is stored in of name $wgWikiaSettingsFile
$wgWikiaSettingsFile = "$wgWikiaSettingsDir/$wgDBname.$wgDBprefix";

$wgExtensionMessagesFiles['WikiaAdmin'] = dirname( __FILE__ ) . "/WikiaAdmin.i18n.php";
$wgExtensionFunctions[] = "wfSetupWikiaAdmin";
$wgSpecialPages['WikiaAdmin'] = "WikiaAdmin";
$wgSpecialPageGroups['WikiaAdmin'] = "od";
$wgExtensionCredits['specialpage'][] = array(
	'name'        => "WikiaAdmin",
	'author'      => "[http://www.organicdesign.co.nz/nad User:Nad]",
	'description' => "Manage the wikis in this wikia",
	'url'         => "http://www.organicdesign.co.nz/Extension:WikiaAdmin.php",
	'version'     => WIKIAADMIN_VERSION
);

require_once( "$IP/includes/SpecialPage.php" );

/**
 * Define a new class based on the SpecialPage class
 */
class WikiaAdmin extends SpecialPage {

	# LocalSettings hash for all sub-wikis
	var $settings  = array();

	# Posted form data
	var $curid     = '';
	var $newid     = '';
	var $sitename  = '';
	var $dump      = '';
	var $domains   = '';
	var $domain    = '';
	var $subdomain = '';
	var $user      = '';
	var $pass      = '';
	var $pass2     = '';

	# Form processing results
	var $error     = '';
	var $result    = '';

	function __construct() {

		# Add the special page to the environment
		SpecialPage::SpecialPage( 'WikiaAdmin', 'sysop', true, false, false, false );

		# Load the localsettings array for this master wiki (by DB.prefix)
		$this->loadSettings();

		# Apply any localsettings relating to this sub-wiki (by domain)
		$this->applySettings();
	}

	/**
	 * Override SpecialPage::execute()
	 */
	function execute() {
		global $wgOut, $wgRequest, $wgWikiaDatabaseDumps;
		$this->setHeaders();

		# Die if there are no database dumps to use for new wikis
		if ( count( $wgWikiaDatabaseDumps ) < 1 ) die( wfMsg( 'wa-no-dumps' ) );

		# A form was submitted
		if ( $wgRequest->getText( 'wpSubmit' ) ) {

			# Read in posted values
			$this->curid     = $wgRequest->getText( 'wpCurId' );
			$this->newid     = strtolower( $wgRequest->getText( 'wpNewId' ) );
			$this->sitename  = $wgRequest->getText( 'wpSitename' );
			$this->domains   = $wgRequest->getText( 'wpDomains' );
			$this->domain    = $wgRequest->getText( 'wpDomain' );
			$this->subdomain = $wgRequest->getText( 'wpSubdomain' );
			$this->user      = ucfirst( $wgRequest->getText( 'wpUser', "WikiSysop" ) );
			$this->pass      = $wgRequest->getText( 'wpPass' );
			$this->pass2     = $wgRequest->getText( 'wpPass2' );
			$this->dump      = $wgRequest->getText( 'wpDump' );

			# Process the form
			$this->processForm();

			# Render any errors or results set during processing
			if ( !empty( $this->error ) )  $wgOut->addHtml( "<div class='errorbox'>{$this->error}</div>" );
			if ( !empty( $this->result ) ) $wgOut->addHtml( "<div class='successbox'>{$this->result}</div>" );
			$wgOut->addHtml( "<div style=\"clear: both\"></div>" );
		}

		# Render the form
		$this->renderForm();

	}

	/**
	 * Render the special page form and populate with posted data or defaults
	 */
	function renderForm() {
		global $wgOut, $wgDBname, $wgJsMimeType, $wgWikiaAdminDomains, $wgWikiaDatabaseDumps;
		$url = Title::newFromText( 'WikiaAdmin', NS_SPECIAL )->getLocalUrl();
		$this->addJavaScript( $wgOut );
		$wgOut->addHtml( "<h2>" . wfMsg( 'wa-title', $wgDBname ) . "</h2>\n" );
		$wgOut->addHtml( "<form action=\"$url\" method=\"POST\" enctype=\"multipart/form-data\">\n" );

		# Wiki ID
		$wgOut->addHtml( wfMsg( 'wa-id' ) . ': ' );
		$options = "<option value=\"new\">" . wfMsg( 'wa-new' ) . "...</option>\n";
		foreach( $this->settings as $id => $settings ) {
			$selected = $this->newid == $id ? " selected" : "";
			$wiki = $settings['sitename'];
			$options .= "<option$selected value=\"$id\">$wiki</option>\n";
		}
		$wgOut->addHtml( "<select id=\"wa-id-select\" onchange=\"wikia_id_select()\" name=\"wpCurId\">$options</select><br />\n" );

		# New wiki ID - revealed if Wiki ID set to "new"
		$wgOut->addHtml( "<div id=\"wa-new-id\">" );
		$wgOut->addHtml( wfMsg( 'wa-id-new' ) . "<br />" );
		$wgOut->addHtml( "<input name=\"wpNewId\" value=\"\" /><br />\n" );
		$wgOut->addHtml( "</div>" );

		# Site name
		$wgOut->addHtml( "<br />" . wfMsg( 'wa-sitename' ) . "<br />" );
		$wgOut->addHtml( "<input name=\"wpSitename\" value=\"{$this->sitename}\" /><br />\n" );

		# Database dump list
		$wgOut->addHtml( "<br />" . wfMsg( 'wa-dumps' ) . "<br />" );
		$options = '';
		foreach ( $wgWikiaDatabaseDumps as $name => $file ) {
			$selected = $this->dump == $name ? " selected" : "";
			$options .= "<option$selected>$name</option>\n";
		}
		$wgOut->addHtml( "<select name=\"wpDump\">$options</select><br />\n" );

		# Domain selection
		$wgOut->addHtml( "<br />" . wfMsg( 'wa-domains' ) . "<br />" );
		if ( $wgWikiaAdminDomains === false ) {
			$wgOut->addHtml( "<textarea name=\"wpDomains\">{$this->domains}</textarea><br />" );
		} else {
			foreach ( $wgWikiaAdminDomains as $domain ) {
					$selected = $this->domain == $domain ? " selected" : "";
					$options .= "<option$selected>$domain</option>\n";
			}
			$wgOut->addHtml( "<select name=\"wpDomain\">$options</select><br />\n" );
			$wgOut->addHtml( "<input name=\"wpSubdomain\" value=\"{$this->subdomain}\" /><br />\n" );
		}
		$wgOut->addHtml( "<i>(" . wfMsg( 'wa-domain-naked' ) . ")</i><br />" );

		# Sysop details
		$wgOut->addHtml( "<div id=\"wa-sysop\">" );
		$wgOut->addHtml( "<br /><table cellpadding=\"0\" cellspacing=\"0\">\n" );
		$wgOut->addHtml( "<tr><td>" . wfMsg( 'wa-user' ) . ":</td>" );
		$wgOut->addHtml( "<td><input name=\"wpUser\" value=\"{$this->user}\" /></td></tr>" );
		$wgOut->addHtml( "<tr><td>" . wfMsg( 'wa-pwd' ) . ":</td> " );
		$wgOut->addHtml( "<td><input type=\"password\" name=\"wpPass\" /></td></tr>" );
		$wgOut->addHtml( "<tr><td>" . wfMsg( 'wa-pwd-confirm' ) . ":</td> " );
		$wgOut->addHtml( "<td><input type=\"password\" name=\"wpPass2\" /></td></tr>" );
		$wgOut->addHtml( "</table></div>" );

		# Form submit
		$wgOut->addHtml( "<br /><input type=\"submit\" name=\"wpSubmit\" value=\"" . wfMsg( 'wa-submit' ) . "\" />" );
		$wgOut->addHtml( "</form>" );
	}

	/**
	 * Create or update a wiki in accord with submitted data
	 */
	function processForm() {

		# Validation (should use friendly JS instead)
		if ( empty( $this->pass ) )                           return $this->error = wfMsg( 'wa_pwd_missing' );
		if ( $this->pass !== $this->pass2 )                   return $this->error = wfMsg( 'wa_pwd_mismatch' );
		if ( empty( $this->newid ) && empty( $this->curid ) ) return $this->error = wfMsg( 'wa_id_missing' );
		if ( preg_match( "|[^a-z0-9_]|i", $this->newid ) )    return $this->error = wfMsg( 'wa_id_invalid' );
		if ( in_array( $this->newid, $this->settings ) )      return $this->error = wfMsg( 'wa_id_exists' );
		if ( empty( $this->domains ) )                        return $this->error = wfMsg( 'wa_domain_missing' );
		if ( empty( $this->sitename ) )                       return $this->error = wfMsg( 'wa_sitename_missing' );
		if ( empty( $this->user ) || preg_match( "|[^a-z0-9_]|i", $this->user ) ) return $this->error = wfMsg( 'wa_user_invalid' );

		if ( $id = $this->newid ) {
			global $wgWikiaDatabaseDumps;

			# Create/Update settings for the selected wiki
			$settings = $this->getSettings();
			$settings[$id]['wgShortName'] = $id;
			$settings[$id]['wgDBprefix']  = $id . '_';
			$settings[$id]['wgSitename']  = $this->sitename;

			# Add the database template to the "wikia" DB
			$sysop = $this->user ? $this->user . ':' . $this->pass : '';
			$file = $wgWikiaDatabaseDumps[$this->dump];
			$cmd = "/var/www/tools/add-db $sysop $file wikia.{$id}_";
			$result = shell_exec( "$cmd 2>&1" );
			if ( strpos( $result, 'successfully' ) ) $this->result = wfMsg( 'wa_success', $this->sitename, $id );
			else return $this->error = $result;

			# Write new settings to this master wiki's settins file (DB.prefix)
			$this->saveSettings();
		}
	}


	/**
	 * The form requires some JavaScript for chained selects
	 */
	function addJavaScript( $out ) {
		global $wgJsMimeType;
		$out->addScript( "<script type='$wgJsMimeType'>
			function wikia_id_select() {
				if ($('#wa-id-select').val() == 'new') $('#wa-new-id').show(); else $('#wa-new-id').hide();
			}
		</script>" );
	}


	/**
	 * Load the settings array from persistent storage
	 */
	function loadSettings() {
		global $wgWikiaSettingsFile;
		if ( file_exists( $wgWikiaSettingsFile ) ) {
			$this->settings = unserialize( file_get_contents( $wgWikiaSettingsFile ) ); 
		}
	}


	/**
	 * Save the settings array to persistent storage
	 */
	function saveSettings() {
		global $wgWikiaSettingsFile;
		file_put_contents( $wgWikiaSettingsFile, serialize( $this->settings ) );
	}


	/**
	 * Return the settings array (for use by other extensions)
	 */
	function getSettings() {
		return $this->settings;
	}


	/**
	 * Update the settings array (for use by other extensions)
	 */
	function setSettings( &$settings ) {
		$this->settings = $settings;
	}


	/**
	 * Apply any of the settings relating to the current wiki
	 */
	function applySettings() {

		# Get domain of this request
		global $wgWikiaImpliedSubdomains;
		$pattern = "/^(" . join( '|', $wgWikiaImpliedSubdomains ) . ")\\./";
		$domain  = preg_replace( $pattern, "", $_SERVER['HTTP_HOST'] );

		# Get sub-wiki ID from domain
		$id = false;
		foreach( $this->settings as $wiki => $settings ) {
			if ( in_array( 'domains', $settings ) ) {
				foreach( split( "\n", $settings['domains'] ) as $d ) {
					$d = preg_replace( $pattern, "", $d );
					if ( $d === $domain ) $id = $wiki;
				}
			}
			if ( in_array( 'domain', $settings ) ) {
				$d = $settings['subdomain'] . '.' . $settings['domain'];
				$d = preg_replace( $pattern, "", $domain );
				if ( $d === $domain ) $id = $wiki;
			}
		}

		# If this is a sub-wiki...
		if ( $id ) {

			# Apply its local settings
			foreach( $this->settings[$id] as $setting => $value ) {
				global $$setting;
				$$setting = $value;
			}

			# Make the uploadpath a sub-directory and fallback on master's images
			global $wgUploadPath, $wgUseSharedUploads, $wgSharedUploadDirectory, $wgSharedUploadPath;
			$wgSharedUploadDirectory = $wgUploadPath;
			$wgUploadPath .= '/' . $id;
			if ( !is_dir( $wgUploadPath ) ) mkdir( $wgUploadPath );
			$wgUseSharedUploads = true;
			$wgSharedUploadPath = "http://" . $_SERVER['HTTP_HOST'] . "/files";

		}
	}
}

/*
 * Initialise the new special page
 */
function wfSetupWikiaAdmin() {
	global $wgWikiaAdmin;
	$wgWikiaAdmin = new WikiaAdmin();
	SpecialPage::addPage( $wgWikiaAdmin );
}

