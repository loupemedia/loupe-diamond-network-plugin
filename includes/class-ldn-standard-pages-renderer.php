<?php
/**
 * Legal page renderer — git-versioned packs, never C1 or C2 (PRD-021 CP137).
 *
 * Test intent: no pack emits a present-tense mechanism claim for a capability
 * that is off.
 *
 * @package LoupeDiamondNetwork
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once LDN_PLUGIN_DIR . 'components/trait-ldn-chrome.php';

final class LDN_Standard_Pages_Renderer {

    use LDN_Trait_Chrome;

    const LAST_UPDATED = '25 August 2026';

    /** @var LDN_Config */
    private $config;

    /**
     * @param LDN_Config $config
     */
    public function __construct(LDN_Config $config) {
        $this->config = $config;
    }

    /**
     * @param LDN_Page_Context $ctx
     * @param string           $page_id
     * @return string
     */
    public function render_head(LDN_Page_Context $ctx, $page_id) {
        $title = $this->page_title($page_id, $ctx->site_id);
        $html = '<meta name="robots" content="noindex, follow">' . "\n";
        $html .= '<title>' . esc_html($title) . '</title>' . "\n";
        return $html;
    }

    /**
     * @param LDN_Page_Context $ctx
     * @param string           $page_id
     * @return string
     */
    public function render(LDN_Page_Context $ctx, $page_id) {
        $profile = $this->config->get_content_profile($ctx->site_id);
        if (!is_array($profile)) {
            $profile = array();
        }

        $heading = $this->heading_title($page_id);
        $body = $this->page_body($ctx, $page_id);
        if ($body === '') {
            return '';
        }

        $html = '<article class="ldn-page-shell ldn-legal-page">';
        $html .= $this->theme_style_block($profile);
        $html .= '<style>.ldn-legal-page{max-width:var(--ldn-measure,68ch);margin:0 auto;'
            . 'padding:var(--ldn-padding,1.25rem);color:var(--ldn-text);}'
            . '.ldn-legal-page h1{font-family:var(--ldn-font-title,Georgia,serif);'
            . 'font-size:1.75rem;line-height:1.25;margin:0 0 0.5rem;}'
            . '.ldn-legal-page h2{font-size:1.15rem;margin:1.75rem 0 0.5rem;}'
            . '.ldn-legal-page p,.ldn-legal-page li{line-height:1.55;}'
            . '.ldn-legal-page .ldn-legal-updated{color:var(--ldn-secondary-text);font-size:0.9rem;}'
            . '@media (max-width:400px){.ldn-legal-page{padding:1rem;}.ldn-legal-page h1{font-size:1.4rem;}}'
            . '</style>';
        $html .= '<h1>' . esc_html($heading) . '</h1>';
        $html .= '<p class="ldn-legal-updated">Last updated ' . esc_html(self::LAST_UPDATED) . '.</p>';
        $html .= $body;
        $html .= '</article>';
        return $html;
    }

    /**
     * @param string $page_id
     * @param string $site_id
     * @return string
     */
    public function heading_title($page_id) {
        $titles = array(
            'privacy' => 'Privacy policy',
            'terms' => 'Terms of use',
            'cookie_notice' => 'Cookies',
            'disclosure' => 'Affiliate disclosure',
            'impressum' => 'Impressum',
        );
        return isset($titles[$page_id]) ? $titles[$page_id] : 'Legal';
    }

    /**
     * @param string $page_id
     * @param string $site_id
     * @return string
     */
    public function page_title($page_id, $site_id) {
        return $this->heading_title($page_id) . ' - ' . $this->brand_name($site_id);
    }

    /**
     * @param LDN_Page_Context $ctx
     * @param string           $page_id
     * @return string
     */
    private function page_body(LDN_Page_Context $ctx, $page_id) {
        switch ($page_id) {
            case 'privacy':
                return $this->privacy_body($ctx);
            case 'terms':
                return $this->terms_body($ctx);
            case 'cookie_notice':
                return $this->cookies_body($ctx);
            case 'disclosure':
                return $this->disclosure_body($ctx);
            case 'impressum':
                return $this->impressum_body($ctx);
            default:
                return '';
        }
    }

    /**
     * @param LDN_Page_Context $ctx
     * @return string
     */
    private function privacy_body(LDN_Page_Context $ctx) {
        $entity = $this->company_name();
        $brand = $this->brand_name($ctx->site_id);
        $mailbox = $this->mailbox($ctx->site_id);
        $address = $this->address_html();
        $jurisdiction = $this->jurisdiction_for($ctx->country_code);

        $html = $this->p(
            $entity . ' publishes ' . $brand . '. We are the controller of personal '
            . 'information collected through this site. Our business address is:'
        );
        $html .= $address;
        $html .= $this->h2('What we collect');
        $html .= $this->p(
            'This site is a publishing and research service. It does not sell diamonds '
            . 'or jewellery and it does not open customer accounts. We may process:'
        );
        $html .= '<ul><li>technical logs such as IP address, browser type and the pages requested</li>'
            . '<li>messages you send to the addresses published on this site</li>'
            . '<li>affiliate click records when you follow a retailer link we disclose</li></ul>';
        $html .= $this->p(
            'We do not run subscriber accounts today. If that changes we will update this page '
            . 'before any account data is collected.'
        );
        $html .= $this->h2('Why we process it');
        $html .= $this->p(
            'Logs keep the site working and help us investigate abuse. Messages are answered. '
            . 'Affiliate records exist so we can attribute a referred visit to a retailer. '
            . 'We do not sell personal information.'
        );
        $html .= $this->jurisdiction_privacy($jurisdiction);
        $html .= $this->h2('How long we keep it');
        $html .= $this->p(
            'Server logs are kept for a short operational window and then deleted. '
            . 'Email we receive is kept for as long as the correspondence remains useful, '
            . 'then deleted. We do not publish a retention number for advertising events '
            . 'because no pruning job is live on that store yet.'
        );
        $html .= $this->h2('Your rights');
        $html .= $this->p(
            'You can ask what information we hold about you, ask us to correct it or ask us '
            . 'to delete it. Write to ' . $mailbox . '. We have not opened a dedicated '
            . 'statutory request desk. That mailbox is how you reach us.'
        );
        $html .= $this->p(
            'If you are in the EEA or the UK you may also complain to your local supervisory '
            . 'authority. We have not appointed an Article 27 representative.'
        );
        return $html;
    }

    /**
     * @param LDN_Page_Context $ctx
     * @return string
     */
    private function terms_body(LDN_Page_Context $ctx) {
        $entity = $this->company_name();
        $brand = $this->brand_name($ctx->site_id);
        $html = $this->p(
            'These terms cover use of ' . $brand . ', a website published by ' . $entity . '. '
            . 'They are terms of use, not terms of sale. Nothing on this site is an offer '
            . 'to sell a diamond, a ring or any other product.'
        );
        $html .= $this->h2('The information on this site');
        $html .= $this->p(
            'Price figures, size figures and retailer lists are research. They are built from '
            . 'feeds and public listings that change during the day. A number on a page can '
            . 'be stale by the time you read it. Do not treat it as a quote, a valuation or '
            . 'advice to buy or sell a particular stone.'
        );
        $html .= $this->p(
            'Retailer names and product links appear because those firms are in the dataset '
            . 'or because we have an affiliate relationship we disclose. Inclusion is not an '
            . 'endorsement of every item they sell.'
        );
        $html .= $this->h2('Your use of the site');
        $html .= $this->p(
            'You may read the pages, share a link and quote short extracts with attribution. '
            . 'You may not scrape the site in a way that overloads it, republish our tables '
            . 'as your own dataset or present our figures as a live feed you operate.'
        );
        $html .= $this->h2('Liability');
        $html .= $this->p(
            'The site is provided as is. ' . $entity . ' is not liable for a purchase you make '
            . 'from a retailer you found here, or for a decision you make from a price or '
            . 'size figure on a page. To the extent the law allows, our liability for use '
            . 'of the site is limited to the amount you paid us to access it in the last '
            . 'twelve months, which is zero.'
        );
        $html .= $this->h2('Intellectual property');
        $html .= $this->p(
            'The text, tables, charts and software on this site belong to ' . $entity . ' '
            . 'or to the people who licensed them to us. Retailer names and marks belong '
            . 'to those retailers.'
        );
        return $html;
    }

    /**
     * @param LDN_Page_Context $ctx
     * @return string
     */
    private function cookies_body(LDN_Page_Context $ctx) {
        $html = $this->p(
            'This page says what is stored on your device when you use this site, and why.'
        );
        $html .= $this->h2('What we set today');
        $has_gtm = class_exists('LDN_Analytics')
            && LDN_Analytics::container_id_for_site($ctx->site_id) !== '';
        if ($has_gtm) {
            $html .= $this->p(
                'We set first-party cookies that WordPress needs to run the site: a session for '
                . 'people who log into the admin screens, and the cookies that remember a cookie '
                . 'test on those screens.'
            );
            $html .= $this->p(
                'Public pages also load Google Tag Manager, which sets Google Analytics cookies '
                . 'so we can see which pages and tools are used. There is no cookie preference '
                . 'centre on this site yet.'
            );
            return $html;
        }
        $html .= $this->p(
            'We set first-party cookies that WordPress needs to run the site: a session for '
            . 'people who log into the admin screens, and the cookies that remember a cookie '
            . 'test on those screens. Public readers do not need an account and we do not '
            . 'set non-essential cookies on public pages today.'
        );
        if (!$this->capability('cookie_consent_manager')) {
            $html .= $this->p(
                'There is no cookie preference centre because there is nothing non-essential '
                . 'to turn off. If we introduce non-essential cookies we will ask for your '
                . 'consent first and this page will change before they are set.'
            );
        }
        if (!$this->capability('third_party_pixels')) {
            $html .= $this->p(
                'We do not run third-party advertising or analytics pixels on these pages today.'
            );
        }
        return $html;
    }

    /**
     * @param LDN_Page_Context $ctx
     * @return string
     */
    private function disclosure_body(LDN_Page_Context $ctx) {
        $brand = $this->brand_name($ctx->site_id);
        $html = $this->p(
            $brand . ' is an independent publisher. We write about diamond prices, sizes and '
            . 'retailers. Some links on this site are affiliate links. If you follow one and '
            . 'buy something, we may be paid a commission. That does not change the price '
            . 'you pay.'
        );
        $html .= $this->h2('How we stay independent');
        $html .= $this->p(
            'A retailer cannot pay to change a price figure, a size figure or a ranking. '
            . 'Editorial pages are not for sale. Display ads, when they appear, are labelled '
            . 'and are separate from the research.'
        );
        $html .= $this->p(
            'We may write about a retailer we also have an affiliate relationship with. The '
            . 'relationship is disclosed. The copy still has to be supportable from the data.'
        );
        return $html;
    }

    /**
     * @param LDN_Page_Context $ctx
     * @return string
     */
    private function impressum_body(LDN_Page_Context $ctx) {
        if (strtolower($ctx->country_code) !== 'de') {
            return '';
        }
        $html = $this->p(
            'Angaben gemaess TMG / DDG. Provider identification for readers in Germany.'
        );
        $html .= $this->p($this->company_name());
        $html .= $this->address_html();
        $html .= $this->p('Contact: ' . $this->mailbox($ctx->site_id));
        return $html;
    }

    /**
     * @param string $jurisdiction
     * @return string
     */
    private function jurisdiction_privacy($jurisdiction) {
        if ($jurisdiction === 'us') {
            return $this->h2('Notice at collection (California)')
                . $this->p(
                    'In the last twelve months we have collected the categories listed above. '
                    . 'We collect them to operate the site and to attribute affiliate visits. '
                    . 'We do not sell personal information and we do not share it for '
                    . 'cross-context behavioural advertising. California residents can write '
                    . 'to the mailbox below to ask what we hold or to ask us to delete it.'
                );
        }
        if ($jurisdiction === 'uk') {
            return $this->p(
                'For readers in the United Kingdom this processing is described under UK GDPR '
                . 'and PECR. The lawful bases we rely on are legitimate interests in running '
                . 'a public research site, and steps you ask us to take when you email us.'
            );
        }
        if ($jurisdiction === 'eu') {
            return $this->p(
                'For readers in the EEA this processing is described under the GDPR. The '
                . 'lawful bases we rely on are legitimate interests in running a public '
                . 'research site, and steps you ask us to take when you email us.'
            );
        }
        if ($jurisdiction === 'au') {
            return $this->p(
                'For readers in Australia this notice is given under the Privacy Act and the '
                . 'Australian Privacy Principles. Australian Consumer Law still applies to '
                . 'how we describe our research. It does not turn a price figure into a quote.'
            );
        }
        if ($jurisdiction === 'ca') {
            return $this->p(
                'For readers in Canada this notice is given under PIPEDA and, for readers in '
                . 'Quebec, Law 25. You can ask us for access to or deletion of information '
                . 'that identifies you.'
            );
        }
        return $this->p(
            'Wherever you live, you can ask us for access to or deletion of information that '
            . 'identifies you. We apply that standard even where a local statute is not named '
            . 'on this page.'
        );
    }

    /**
     * @param string $country
     * @return string
     */
    public function jurisdiction_for($country) {
        $catalogue = $this->config->get_standard_pages();
        foreach ((isset($catalogue['jurisdictions']) ? $catalogue['jurisdictions'] : array()) as $name => $spec) {
            $codes = isset($spec['countries']) && is_array($spec['countries']) ? $spec['countries'] : array();
            if (in_array($country, $codes, true)) {
                return (string) $name;
            }
        }
        return '';
    }

    /**
     * @param string $name
     * @return bool
     */
    public function capability($name) {
        $catalogue = $this->config->get_standard_pages();
        $caps = isset($catalogue['capabilities']) && is_array($catalogue['capabilities'])
            ? $catalogue['capabilities']
            : array();
        if (!array_key_exists($name, $caps)) {
            return false;
        }
        $value = $caps[$name];
        return $value === true;
    }

    /**
     * @return string
     */
    private function company_name() {
        $entity = $this->entity();
        return isset($entity['company_name']) ? (string) $entity['company_name'] : 'Loupe Media Network Pty Ltd';
    }

    /**
     * @param string $site_id
     * @return string
     */
    private function brand_name($site_id) {
        $site = $this->config->get_site($site_id);
        if (is_array($site) && !empty($site['brand_name'])) {
            return (string) $site['brand_name'];
        }
        return $site_id;
    }

    /**
     * @param string $site_id
     * @return string
     */
    private function mailbox($site_id) {
        $entity = $this->entity();
        $overrides = isset($entity['dsar_mailbox_by_site']) && is_array($entity['dsar_mailbox_by_site'])
            ? $entity['dsar_mailbox_by_site']
            : array();
        if (!empty($overrides[$site_id])) {
            return (string) $overrides[$site_id];
        }
        return isset($entity['dsar_mailbox']) ? (string) $entity['dsar_mailbox'] : '';
    }

    /**
     * @return string
     */
    private function address_html() {
        $entity = $this->entity();
        $lines = isset($entity['business_address']) && is_array($entity['business_address'])
            ? $entity['business_address']
            : array();
        if ($lines === array()) {
            return '';
        }
        $escaped = array();
        foreach ($lines as $line) {
            $escaped[] = esc_html((string) $line);
        }
        return '<p>' . implode('<br>', $escaped) . '</p>'
            . $this->p('This is a business address, not the registered office.');
    }

    /**
     * @return array
     */
    private function entity() {
        $catalogue = $this->config->get_standard_pages();
        return isset($catalogue['legal_entity']) && is_array($catalogue['legal_entity'])
            ? $catalogue['legal_entity']
            : array();
    }

    /**
     * @param string $text
     * @return string
     */
    private function p($text) {
        return '<p>' . esc_html($text) . '</p>';
    }

    /**
     * @param string $text
     * @return string
     */
    private function h2($text) {
        return '<h2>' . esc_html($text) . '</h2>';
    }
}
