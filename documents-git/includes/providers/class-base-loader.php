<?php

abstract class BaseLoader {
    protected static $GITHUB_MARKDOWN_API = 'https://api.github.com/markdown';
    protected static $PROVIDER;  # Needs to be set for every subclass

    # Can vary for self-hosted versions of the platforms
    protected $domain;

    # Will dependent on url shortcode attribute, set during parsing url attribute
    protected $owner;
    protected $repo;
    protected $branch;
    protected $file_path;
    protected $user;
    protected $token;

    public function __construct() {
        if (!isset(static::$PROVIDER)) {
            throw new LogicException("Class property PROVIDER must be set for " . static::class);
        }
        $provider = strtolower(static::$PROVIDER);
        add_shortcode("git-$provider-markdown", array($this, 'doMarkdown'));
        add_shortcode("git-$provider-jupyter", array($this, 'doJupyter'));
        add_shortcode("git-$provider-checkout", array($this, 'doCheckout'));
        add_shortcode("git-$provider-history", array($this, 'doHistory'));
    }

    /**
     * The API specific function to return the raw Markdown document and HTTP response code.
     * In case of an API error, the response body is not used.
     *
     * @return mixed array of response body and HTTP response code
     */
    abstract protected function get_document();

    /**
     * The API specific function to return the raw date of the last commit of the file and HTTP response code.
     * In case of an API error, the date string variable is not used.
     *
     * @return mixed array of raw date string and response HTTP code
     */
    abstract protected function get_checkout_datetime();

    /**
     * The API specific function to return the response JSON array for all commits of the file and HTTP response code.
     * In case of an API error, the response JSON array is not used.
     *
     * @return mixed array of associative array of the response JSON and response HTTP code
     */
    abstract protected function get_history();

    /**
     * The API specific function to parse the name, date and message per commit.
     *
     * @param $commit array The associative array for a single commit from the JSON response
     * @return mixed array of name, date and message
     */
    abstract protected function extract_history_from_commit_json(&$commit);

    /**
     * The API specific function to retrieve the URL needed to use with https://nbviewer.jupyter.org
     */
    abstract protected function get_nbviewer_url();

    /**
     * The callback function for the "jupyter" shortcode action. Currently only available for Github
     * due to nbviewer.jupyter.org limitations.
     *
     * Original implementation: https://github.com/ghandic/nbconvert.
     *
     * @param $sc_attrs array Shortcode attributes
     * @return string HTML of the whole Jupyter notebook processed by nbviewer.jupyter.org
     *
     */
    public function doJupyter($sc_attrs)
    {
        $get_url = $this->get_nbviewer_url();

        $wp_remote = wp_remote_get($get_url);
        $html = wp_remote_retrieve_body($wp_remote);
        $response_code = wp_remote_retrieve_response_code($wp_remote);

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        $node = $dom->getElementById('notebook-container');
        if ($node) {
            $inner_html = '';
            $children = $node->childNodes;

            foreach($children as $child) {
                $inner_html.= $node->ownerDocument->saveHTML($child);
            }
        } else {
            $response_code = 404;
        }

        switch ($response_code) {
            case 200:
                break;
            case 404:
                $inner_html = "<h1>404 - Not found</h1>Document not found";
                break;
            default:
                $inner_html = "<h1>500 - Server Error</h1>";
        }

        return '<div class="nbconvert">' . $inner_html . '</div>';
    }

    /**
     * The callback function for the "markdown" shortcode action.
     *
     * @param $sc_attrs array Shortcode attributes
     * @return string HTML of the whole Markdown document processed by Github's markdown endpoint
     *
     */
    public function doMarkdown($sc_attrs)
    {
        list($raw_markdown, $response_code) = $this->get_raw_document($sc_attrs);

        switch ($response_code) {
            case 200:
                break;
            case 404:
                $raw_markdown = "# 404 - Not found\nDocument not found.";
                break;
            case 401:
                $raw_markdown = "# 401 - Bad credentials.\nPlease review access token for user " . $this->user;
                break;
            case 403:
                $raw_markdown = "# 403 - Bad credentials.\nPlease review access token for user " . $this->user;
                break;
            default:
                $raw_markdown = "# 500 - Server Error.\n$raw_markdown";
        }

        $args = array(
            'body' => json_encode(array(
                "text" => $raw_markdown
            )),
            'headers' => array(
                'Content-Type' => 'application/json'
            )
        );

        // Add the Github credentials to have high /markdown rate limits
        $GITHUB_USER = MARKDOWNGIT_CONFIG['Github']['user'];
        $GITHUB_TOKEN = MARKDOWNGIT_CONFIG['Github']['token'];
        if (!empty($GITHUB_USER) or !empty($GITHUB_TOKEN)) {
            $args['headers']['Authorization'] = 'Basic ' . base64_encode($GITHUB_USER . ':' . $GITHUB_TOKEN);
        }

        $response = wp_remote_post(self::$GITHUB_MARKDOWN_API, $args);
        $html_body = wp_remote_retrieve_body($response);

        return '<div class="markdown-body">' . $html_body . '</div>';
    }

    /**
     * The callback function for the "checkout" shortcode action.
     *
     * Adapted from https://github.com/ghandic/nbconvert.
     *
     * @param $sc_attrs array Shortcode attributes
     * @return string HTML for the checkout span
     */
    public function doCheckout($sc_attrs)
    {
        $url = $this->extract_attributes($sc_attrs);
        $this->set_repo_details($url);

        list($datetime_str, $response_code) = $this->get_checkout_datetime();

        switch ($response_code) {
            case 200:
                $datetime = strtotime($datetime_str);
                $html_body = date('d/m/Y H:i:s', $datetime);
                break;
            case 401:
                $html_body = "401 - Invalid credentials for user " . $this->user;
                break;
            case 404:
                $html_body = "404 - Post not found on $url";
        }

        return '
        <div class="markdown-github">
          <div class="markdown-github-labels">
            <label class="github-link">
              <a href="' . $url . '" target="_blank">Check it out on ' . static::$PROVIDER . '</a>
              <label class="github-last-update"> Last updated: ' . $html_body . '</label>
            </label>
          </div>
        </div>';
    }

    /**
     * The callback function for the "history" shortcode action.
     *
     * @param $sc_attrs array Shortcode attributes
     * @return string HTML for the Last X commits section
     */
    public function doHistory($sc_attrs)
    {
        $url = $this->extract_attributes($sc_attrs);
        if (empty($this->limit)) {
            $this->limit = 5;
        }
        $this->set_repo_details($url);

        $html_string = '<hr style="margin: 20px 0; width: 70%; border-top: 1.5px solid #aaaaaa;" /><article class="markdown-body"><h2><strong><a target="_blank" href="' . $url . '">Post history - Last 5 commits</a></strong></h2>';

        $commits_json = $this->get_history();

        $i = 0;
        foreach ($commits_json as $item) {
            if ($i == intval($this->limit)) break;
            list($name, $date, $message) =  $this->extract_history_from_commit_json($item);
            $html_string .= "<p>";
            $html_string .= "<strong>" . date('d/m/Y H:i:s', strtotime($date)) . "</strong> - $message";
            $html_string .= " ( $name )";
            $html_string .= '</p>';
            $i++;
        }
        $html_string .= '</article>';

        return $html_string;
    }

    /**
     * Determines and returns the correct Authorization header, depending on the API
     *
     * @return string Base64 encoded Basic Authorization header
     */
    protected function get_auth_header(){
        return 'Basic ' . base64_encode($this->user . ':' . $this->token);
    }

    /**
     * Extract the relevant attributes from the URL provided by the "url" shortcode attribute and set
     * class attributes to access them in other functions.
     *
     * @param $url string URL of the file to be rendered
     */
    protected function set_repo_details($url)
    {
        $url_parsed = parse_url($url);
        $domain = $url_parsed['host'];
        $path = $url_parsed['path'];

        $exploded_path = explode('/', $path);
        $owner = $exploded_path[1];
        $repo = $exploded_path[2];
        $branch = $exploded_path[4];
        $file_path = implode('/', array_slice($exploded_path, 5));

        $this->domain = $domain;
        $this->owner = $owner;
        $this->repo = $repo;
        $this->branch = $branch;
        $this->file_path = $file_path;

    }

    private function get_raw_document($sc_attrs)
    {
        $url = $this->extract_attributes($sc_attrs);
        $this->set_repo_details($url);
        list($raw_document, $response_code) = $this->get_document();

        return array($raw_document, $response_code);
    }

    /**
     * Extracts the attributes from a shortcode. All attributes of all shortcodes are extracted,
     * but not necessarily passed, so they default to an empty string.
     *
     * It also sets the class attributes "user", "token" and "limit" from config.json or shortcode attribute.
     *
     * @param $attrs array Attributes of the shortcode
     * @return string parsed url
     */
    private function extract_attributes($attrs) {

        $attrs = array_change_key_case((array)$attrs, CASE_LOWER);
        extract(shortcode_atts(array(
                'url' => "",
                'user' => "",
                'token' => "",
                'limit' => ""
            ), $attrs
            )
        );

        $this->user = ($user === '') ? (MARKDOWNGIT_CONFIG[static::$PROVIDER]["user"]) : ($user);
        $this->token = ($token === '') ? (MARKDOWNGIT_CONFIG[static::$PROVIDER]["token"]) : ($token);
        $this->limit = ($limit === '') ? (MARKDOWNGIT_CONFIG["limit"]) : ($limit);

        return $url;
    }
}