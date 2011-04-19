<?php

class WebDriver_Driver {
  protected $session_id;
  protected $server_url;
  private static $status_codes = array(
    0 => array("Success", " The command executed successfully."),
    7 => array("NoSuchElement", " An element could not be located on the page using the given search parameters."),
    8 => array("NoSuchFrame", " A request to switch to a frame could not be satisfied because the frame could not be found."),
    9 => array("UnknownCommand", " The requested resource could not be found, or a request was received using an HTTP method that is not supported by the mapped resource."),
    10 => array("StaleElementReference", " An element command failed because the referenced element is no longer attached to the DOM."),
    11 => array("ElementNotVisible", " An element command could not be completed because the element is not visible on the page."),
    12 => array("InvalidElementState", " An element command could not be completed because the element is in an invalid state (e.g. attempting to click a disabled element)."),
    13 => array("UnknownError", " An unknown server-side error occurred while processing the command."),
    15 => array("ElementIsNotSelectable", " An attempt was made to select an element that cannot be selected."),
    17 => array("JavaScriptError", " An error occurred while executing user supplied JavaScript."),
    19 => array("XPathLookupError", " An error occurred while searching for an element by XPath."),
    23 => array("NoSuchWindow", " A request to switch to a different window could not be satisfied because the window could not be found."),
    24 => array("InvalidCookieDomain", " An illegal attempt was made to set a cookie under a different domain than the current page."),
    25 => array("UnableToSetCookie", " A request to set a cookie's value could not be satisfied."),
    28 => array("Timeout", " A command did not complete before its timeout expired."),
  );
  
  protected function __construct($server_url, $capabilities) {
    $this->server_url = $server_url;
    
    $payload = array("desiredCapabilities" => $capabilities);
    $response = $this->execute("POST", "/session", $payload);
    
    // Parse out session id
    preg_match("/\nLocation:.*\/(.*)\n/", $response['header'], $matches);
    if (count($matches) > 0) {
      $this->session_id = trim($matches[1]);
    } else {
      $message = "Did not get a session id from $server_url\n";
      if (!empty($response['body'])) {
        $message .= $response['body'];
      } else if (!empty($response['header'])) {
        $message .= $response['header'];
      } else {
        $message .= "No response from server.";
      }
      throw new Exception($message);
    }
  }
  
  public static function InitAtSauce($sauce_username, $sauce_key, $os, $browser, $version = false) {
    $capabilities = array(
      'javascriptEnabled' => true,
      'platform' => strtoupper($os),
      'browserName' => $browser,
    );
    if ($version) {
      $capabilities["version"] = $version;
    }
    return new WebDriver_Driver("http://" . $sauce_username . ":" . $sauce_key . "@ondemand.saucelabs.com:80/wd/hub", $capabilities);
  }
  
  public static function InitAtLocal($port, $browser) {
    $capabilities = array(
      'javascriptEnabled' => true,
      'browserName' => $browser,
    );
    if (strcasecmp($browser, "iphone") == 0 || strcasecmp($browser, "android") == 0) {
      return new WebDriver_Driver("http://localhost:$port/hub", $capabilities);
    } else {
      return new WebDriver_Driver("http://localhost:$port/wd/hub", $capabilities);
    }
  }
  
  public function running_at_sauce() {
    return (strpos($this->server_url, "saucelabs.com") !== false);
  }
  
  public function sauce_url() {
    if ($this->running_at_sauce()) {
      return "https://saucelabs.com/jobs/{$this->session_id}";
    } else {
      return false;
    }
  }
  
  public function execute($http_type, $relative_url, $payload = null) {
    if ($payload !== null) {
      $payload = json_encode($payload);
    }
    $relative_url = str_replace(':sessionId', $this->session_id, $relative_url);
    $full_url = $this->server_url . $relative_url;
    $response = WebDriver::Curl($http_type, $full_url, $payload);
    if (isset($response['body'])) {
      $this->check_response_status($response['body'], $payload);
    }
    return $response;
  }
  
  private function check_response_status($body, $payload) {
    $array = json_decode(trim($body), true);
    if (!is_null($array)) {
      $response_status_code = $array["status"];
      if (!self::$status_codes[$response_status_code]) {
        throw new Exception("Unknown status code $response_status_code returned from server.\n$body");
      }
      if ($response_status_code != 0) {
        $message = $response_status_code . " - " . self::$status_codes[$response_status_code][0] . " - " . self::$status_codes[$response_status_code][1] . "\n";
        $message .= "Payload: " . print_r($payload, true) . "\n";
        if (isset($array['value']['message'])) {
          $message .= "Message: " . $array['value']['message'] . "\n";
        } else {
          $message .= "Response: " . $body . "\n";
        }
        throw new Exception($message);
      }
    }
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId
  public function quit() {
    $this->execute("DELETE", "/session/:sessionId");
  }
  
  /********************************************************************
   * Getters
   */
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId
  public function get_capabilities() {
    $response = $this->execute("GET", "/session/:sessionId");
    return WebDriver::GetJSONValue($response);
  }

  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/url
  public function get_url() {
    $response = $this->execute("GET", "/session/:sessionId/url");
    return WebDriver::GetJSONValue($response);
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/title
  public function get_title() {
    $response = $this->execute("GET", "/session/:sessionId/title");
    return WebDriver::GetJSONValue($response);
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/source
  public function get_source() {
    $response = $this->execute("GET", "/session/:sessionId/source");
    return WebDriver::GetJSONValue($response);
  }
  
  public function get_text() {
    $tries = $this->running_at_sauce() ? 3 : 1; // Sauce Labs has trouble with this tag sometimes, so we give it a couple tries
    for ($i = 1; $i <= $tries; $i++) {
      try {
        $result = $this->get_element("tag name=body")->get_text();
        break;
      } catch (Exception $e) {
        // try again
      }
    }
    if (!isset($result)) {
      throw new Exception("Could not get body text after $tries tries");
    }
    return $result;
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/screenshot
  public function get_screenshot() {
    $response = $this->execute("GET", "/session/:sessionId/screenshot");
    $base64_encoded_png = WebDriver::GetJSONValue($response);
    return base64_decode($base64_encoded_png);
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/ime/available_engines
  // Not supported as of Selenium 2.0b3
  public function get_all_ime_engines() {
    $response = $this->execute("GET", "/session/:sessionId/ime/available_engines");
    return WebDriver::GetJSONValue($response);
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/ime/active_engine
  // Not supported as of Selenium 2.0b3
  public function get_ime_engine() {
    $response = $this->execute("GET", "/session/:sessionId/ime/active_engine");
    return WebDriver::GetJSONValue($response);
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/ime/activated
  // Not supported as of Selenium 2.0b3
  public function is_ime_active() {
    $response = $this->execute("GET", "/session/:sessionId/ime/activated");
    return WebDriver::GetJSONValue($response);
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/element
  public function get_element($locator) {
    $payload = WebDriver::ParseLocator($locator);
    $response = $this->execute("POST", "/session/:sessionId/element", $payload);
    $element_id = WebDriver::GetJSONValue($response, "ELEMENT");
    return new WebDriver_WebElement($this, $element_id, $locator);
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/elements
  public function get_all_elements($locator) {
    $payload = WebDriver::ParseLocator($locator);
    $response = $this->execute("POST", "/session/:sessionId/elements", $payload);
    $element_ids = WebDriver::GetJSONValue($response, "ELEMENT");
    $elements = array();
    foreach ($element_ids as $element_id) {
      $elements[] = new WebDriver_WebElement($this, $element_id, $locator);
    }
    return $elements;
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/element/active
  public function get_active_element() {
    $response = $this->execute("POST", "/session/:sessionId/element/active");
    $element_id = WebDriver::GetJSONValue($response, "ELEMENT");
    return new WebDriver_WebElement($this, $element_id, "active=true");
  }
  
  public function is_element_present($locator) {
    try {
      $this->get_element($locator);
      $is_element_present = true;
    } catch (Exception $e) {
      $is_element_present = false;
    }
    return $is_element_present;
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/window_handle
  public function get_window_handle() {
    $response = $this->execute("GET", "/session/:sessionId/window_handle");
    return WebDriver::GetJSONValue($response);
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/window_handles
  public function get_all_window_handles() {
    $response = $this->execute("GET", "/session/:sessionId/window_handles");
    return WebDriver::GetJSONValue($response);
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/speed
  // Not supported as of Selenium 2.0b3
  public function get_input_speed() {
    $response = $this->execute("GET", "/session/:sessionId/speed");
    return WebDriver::GetJSONValue($response);
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/cookie
  public function get_all_cookies() {
    $response = $this->execute("GET", "/session/:sessionId/cookie");
    return WebDriver::GetJSONValue($response);
  }
  
  public function get_cookie($name, $property = null) {
    $all_cookies = $this->get_cookies();
    foreach ($all_cookies as $cookie) {
      if ($cookie['name'] == $name) {
        if (is_null($property)) {
          return $cookie;
        } else {
          return $cookie[$property];
        }
      }
    }
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/orientation
  // Not supported in iPhone as of Selenium 2.0b3
  private function get_orientation() {
    $response = $this->execute("GET", "/session/:sessionId/orientation");
    return WebDriver::GetJSONValue($response);
  }
  public function is_landscape()  { return $this->get_orientation() == "LANDSCAPE"; }
  public function is_portrait()   { return $this->get_orientation() == "PORTRAIT"; }

  /********************************************************************
   * Setters
   */
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/timeouts/async_script
  public function set_async_timeout($milliseconds) {
    $payload = array("ms" => $milliseconds);
    $this->execute("POST", "/session/:sessionId/timeouts/async_script", $payload);
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/timeouts/implicit_wait
  public function set_implicit_wait($milliseconds) {
    $payload = array("ms" => $milliseconds);
    $this->execute("POST", "/session/:sessionId/timeouts/implicit_wait", $payload);
  }

  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/url
  public function load($url) {
    $payload = array("url" => $url);
    $this->execute("POST", "/session/:sessionId/url", $payload);
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/forward
  public function go_forward() {
    $this->execute("POST", "/session/:sessionId/forward");
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/back
  public function go_back() {
    $this->execute("POST", "/session/:sessionId/back");
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/refresh
  public function refresh() {
    $this->execute("POST", "/session/:sessionId/refresh");
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/window
  public function select_window($window_title) {
    $all_window_handles = $this->get_all_window_handles();
    $all_titles = array();
    $current_title = "";
    foreach ($all_window_handles as $window_handle) {
      $payload = array("name" => $window_handle);
      $this->execute("POST", "/session/:sessionId/window", $payload);
      $current_title = $this->get_title();
      $all_titles[] = $current_title;
      if ($current_title == $window_title) {
        break;
      }
    }
    if ($current_title != $window_title) {
      throw new Exception("Could not find window with title <$window_title>. Found " . count($all_titles) . " windows: " . implode("; ", $all_titles));
    }
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/window
  public function close_window() {
    $this->execute("DELETE", "/session/:sessionId/window");
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/ime/deactivate
  // Not supported as of Selenium 2.0b3
  public function deactivate_ime() {
    $this->execute("POST", "/session/:sessionId/ime/deactivate");
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/ime/activate
  // Not supported as of Selenium 2.0b3
  public function activate_ime() {
    $this->execute("POST", "/session/:sessionId/ime/activate");
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/frame
  public function select_frame($identifier) {
    $payload = array("id" => $identifier);
    $this->execute("POST", "/session/:sessionId/frame", $payload);
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/cookie
  public function set_cookie($name, $value, $path = null, $domain = null, $secure = false, $expiry = null) {
    $payload = array(
      'cookie' => array(
        'name' => $name,
        'value' => $value,
        'secure' => $secure, // The documentation says this is optional, but selenium server 2.0b1 throws a NullPointerException if it's not provided
      )
    );
    if (!is_null($path)) {
      $payload['cookie']['path'] = $path;
    }
    if (!is_null($domain)) {
      $payload['cookie']['domain'] = $domain;
    }
    if (!is_null($expiry)) {
      $payload['cookie']['expiry'] = $expiry;
    }
    $this->execute("POST", "/session/:sessionId/cookie", $payload);
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/cookie
  public function delete_all_cookies() {
    $this->execute("DELETE", "/session/:sessionId/cookie");
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/cookie/:name
  public function delete_cookie($name) {
    $this->execute("DELETE", "/session/:sessionId/cookie/" . $name);
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/execute
  public function execute_js_sync($javascript, $arguments = array()) {
    $payload = array(
      "script" => $javascript,
      "args" => $arguments,
    );
    return $this->execute("POST", "/session/:sessionId/execute", $payload);
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/execute_async
  public function execute_js_async($javascript, $arguments = array()) {
    $payload = array(
      "script" => $javascript,
      "args" => $arguments,
    );
    return $this->execute("POST", "/session/:sessionId/execute_async", $payload);
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/speed
  // Not supported as of Selenium 2.0b3
  public function set_input_speed($speed) {
    $payload = array("speed" => $speed);
    $this->execute("POST", "/session/:sessionId/speed", $payload);
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/modifier
  private function send_modifier($modifier_code, $is_down) {
    $payload = array(
      'value' => $modifier_code,
      'isdown' => $is_down
    );
    $this->execute("POST", "/session/:sessionId/modifier", $payload);
  }
  public function ctrl_down()     { send_modifier("U+E009", true); }
  public function ctrl_up()       { send_modifier("U+E009", false); }
  public function shift_down()    { send_modifier("U+E008", true); }
  public function shift_up()      { send_modifier("U+E008", false); }
  public function alt_down()      { send_modifier("U+E00A", true); }
  public function alt_up()        { send_modifier("U+E00A", false); }
  public function command_down()  { send_modifier("U+E03D", true); }
  public function command_up()    { send_modifier("U+E03D", false); }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/orientation
  // Not supported as of Selenium 2.0b3
  private function set_orientation($new_orientation) {
    $payload = array("orientation", $new_orientation);
    $this->execute("POST", "/session/:sessionId/orientation", $payload);
  }
  public function rotate_landscape()  { $this->set_orientation("LANDSCAPE"); }
  public function rotate_portrait()   { $this->set_orientation("PORTRAIT"); }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/moveto
  public function move_cursor($right, $down) {
    $payload = array(
      "xoffset" => $right,
      "yoffset" => $down
    );
    $this->execute("POST", "/session/:sessionId/moveto", $payload);
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/click
  private function click_mouse($button) {
    $payload = array("button" => $button);
    $this->execute("POST", "/session/:sessionId/click", $payload);
  }
  public function click()         { $this->click_mouse(0); }
  public function middle_click()  { $this->click_mouse(1); }
  public function right_click()   { $this->click_mouse(2); }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/buttondown
  public function click_and_hold() {
    $this->execute("POST", "/session/:sessionId/buttondown");
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/buttonup
  public function release_click() {
    $this->execute("POST", "/session/:sessionId/buttonup");
  }
  
  // See http://code.google.com/p/selenium/wiki/JsonWireProtocol#/session/:sessionId/doubleclick
  public function double_click() {
    $this->execute("POST", "/session/:sessionId/doubleclick");
  }
  
  // See https://saucelabs.com/docs/sauce-ondemand#alternative-annotation-methods
  public function set_sauce_context($field, $value) {
    if ($this->running_at_sauce()) {
      $payload = json_encode(array($field => $value));
      $url_parts = parse_url($this->server_url);
      WebDriver::Curl("PUT", "http://" . $url_parts['user'] . ":" . $url_parts['pass'] . "@saucelabs.com/rest/v1/" . $url_parts['user'] . "/jobs/" . $this->session_id, $payload);
    }
  }
  
  /********************************************************************
   * Asserters
   */

  public function assert_url($expected_url) {
    PHPUnit_Framework_Assert::assertEquals($expected_url, $this->get_url(), "Failed asserting that URL is <$expected_url>.");
  }
  
  public function assert_title($expected_title) {
    PHPUnit_Framework_Assert::assertEquals($expected_title, $this->get_title(), "Failed asserting that title is <$expected_title>.");
  }
  
  public function assert_element_present($element_locator) {
    PHPUnit_Framework_Assert::assertTrue($this->is_element_present($element_locator), "Failed asserting that <$element_locator> is present");
  }
  
  public function assert_element_not_present($element_locator) {
    PHPUnit_Framework_Assert::assertFalse($this->is_element_present($element_locator), "Failed asserting that <$element_locator> is not present");
  }
  
  public function assert_string_present($expected_string) {
    $page_text = $this->get_text();
    PHPUnit_Framework_Assert::assertContains($expected_string, $page_text, "Failed asserting that page text contains <$expected_string>.\n$page_text");
  }
  
  public function assert_string_not_present($expected_missing_string) {
    $page_text = $this->get_text();
    PHPUnit_Framework_Assert::assertNotContains($expected_missing_string, $page_text, "Failed asserting that page text does not contain <$expected_missing_string>.\n$page_text");
  }
}