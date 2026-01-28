<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Service;

use Drupal\ai_content_preparation_wizard\Exception\DocumentProcessingException;
use Drupal\ai_content_preparation_wizard\Model\ProcessedWebpage;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Service for processing web pages into markdown documents.
 *
 * Fetches web pages via HTTP, cleans the HTML aggressively to extract
 * main content only, and converts it to markdown format suitable for
 * AI processing with minimal context usage.
 */
final class WebpageProcessor implements WebpageProcessorInterface {

  /**
   * Elements to completely remove from HTML (non-content elements).
   *
   * @var array<string>
   */
  private const REMOVE_ELEMENTS = [
    'script',
    'style',
    'nav',
    'header',
    'footer',
    'aside',
    'form',
    'iframe',
    'noscript',
    'svg',
    'canvas',
    'button',
    'input',
    'select',
    'textarea',
    'video',
    'audio',
    'embed',
    'object',
    'map',
    'area',
    'template',
    'dialog',
    'menu',
    'menuitem',
  ];

  /**
   * Attributes to remove from remaining elements.
   *
   * @var array<string>
   */
  private const REMOVE_ATTRIBUTES = [
    'class',
    'id',
    'style',
    'onclick',
    'onload',
    'onerror',
    'onmouseover',
    'onmouseout',
    'onfocus',
    'onblur',
    'onchange',
    'onsubmit',
    'role',
    'aria-label',
    'aria-labelledby',
    'aria-describedby',
    'aria-hidden',
    'tabindex',
    'data-*',
  ];

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  private LoggerInterface $logger;

  /**
   * Constructs a WebpageProcessor.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger channel factory.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('ai_content_preparation_wizard');
  }

  /**
   * {@inheritdoc}
   */
  public function processUrl(string $url): ProcessedWebpage {
    if (!$this->isValidUrl($url)) {
      throw new DocumentProcessingException(
        sprintf('Invalid URL format: %s', $url),
        $url,
        'webpage_processor',
      );
    }

    $this->logger->info('Processing web page: @url', ['@url' => $url]);

    try {
      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 30,
        'connect_timeout' => 10,
        'headers' => [
          'User-Agent' => 'Mozilla/5.0 (compatible; DrupalAIContentWizard/1.0)',
          'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
          'Accept-Language' => 'en-US,en;q=0.5',
        ],
        'allow_redirects' => [
          'max' => 5,
          'strict' => FALSE,
          'referer' => TRUE,
          'protocols' => ['http', 'https'],
        ],
        'verify' => TRUE,
      ]);

      $statusCode = $response->getStatusCode();
      if ($statusCode >= 400) {
        throw new DocumentProcessingException(
          sprintf('HTTP error %d when fetching URL: %s', $statusCode, $url),
          $url,
          'webpage_processor',
        );
      }

      $html = (string) $response->getBody();

      if (empty($html)) {
        throw new DocumentProcessingException(
          sprintf('Empty response from URL: %s', $url),
          $url,
          'webpage_processor',
        );
      }

      $contentType = $response->getHeaderLine('Content-Type');

      // Detect and convert encoding to UTF-8.
      $encoding = $this->detectEncoding($html, $contentType);
      if ($encoding !== 'UTF-8' && $encoding !== 'utf-8') {
        $converted = @mb_convert_encoding($html, 'UTF-8', $encoding);
        if ($converted !== FALSE) {
          $html = $converted;
        }
      }

    }
    catch (GuzzleException $e) {
      $this->logger->error('Failed to fetch URL @url: @error', [
        '@url' => $url,
        '@error' => $e->getMessage(),
      ]);
      throw new DocumentProcessingException(
        sprintf('Failed to fetch URL %s: %s', $url, $e->getMessage()),
        $url,
        'webpage_processor',
        0,
        $e,
      );
    }

    // Extract metadata before aggressive cleaning.
    $metadata = $this->extractMetadata($html, $url);

    // Clean HTML aggressively.
    $cleanHtml = $this->cleanHtml($html);

    // Convert to markdown.
    $markdown = $this->htmlToMarkdown($cleanHtml);

    // Normalize whitespace.
    $markdown = $this->normalizeWhitespace($markdown);

    if (empty(trim($markdown))) {
      $this->logger->warning('No content extracted from URL: @url', ['@url' => $url]);
      throw new DocumentProcessingException(
        sprintf('No extractable content found on page: %s', $url),
        $url,
        'webpage_processor',
      );
    }

    $this->logger->info('Successfully processed web page: @url (@chars characters)', [
      '@url' => $url,
      '@chars' => mb_strlen($markdown),
    ]);

    return ProcessedWebpage::create(
      url: $url,
      title: $metadata->title ?? $this->urlToFilename($url),
      markdownContent: $markdown,
      metadata: [
        'description' => $metadata->description ?? '',
        'author' => $metadata->author ?? '',
        'word_count' => $metadata->wordCount ?? 0,
        'source_url' => $url,
      ],
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processUrls(array $urls): array {
    $documents = [];

    foreach ($urls as $url) {
      try {
        $documents[] = $this->processUrl($url);
      }
      catch (DocumentProcessingException $e) {
        $this->logger->warning('Skipping failed URL @url: @error', [
          '@url' => $url,
          '@error' => $e->getMessage(),
        ]);
        // Continue processing other URLs.
      }
    }

    return $documents;
  }

  /**
   * {@inheritdoc}
   */
  public function isValidUrl(string $url): bool {
    if (empty($url)) {
      return FALSE;
    }

    // Use Drupal's URL validation.
    if (!UrlHelper::isValid($url, TRUE)) {
      return FALSE;
    }

    // Parse URL for additional checks.
    $parsed = parse_url($url);
    if ($parsed === FALSE) {
      return FALSE;
    }

    // Only allow HTTP and HTTPS.
    if (!isset($parsed['scheme']) || !in_array(strtolower($parsed['scheme']), ['http', 'https'], TRUE)) {
      return FALSE;
    }

    // Require a host.
    if (empty($parsed['host']) || strlen($parsed['host']) < 3) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Detects the character encoding of HTML content.
   *
   * @param string $html
   *   The HTML content.
   * @param string $contentType
   *   The Content-Type header value.
   *
   * @return string
   *   The detected encoding, defaults to UTF-8.
   */
  private function detectEncoding(string $html, string $contentType): string {
    // Check Content-Type header first.
    if (preg_match('/charset=([^\s;]+)/i', $contentType, $matches)) {
      return strtoupper(trim($matches[1], '"\''));
    }

    // Check meta charset tag.
    if (preg_match('/<meta[^>]+charset=["\']?([^"\'\s>]+)/i', $html, $matches)) {
      return strtoupper($matches[1]);
    }

    // Check http-equiv Content-Type meta tag.
    if (preg_match('/<meta[^>]+http-equiv=["\']Content-Type["\'][^>]+content=["\'][^"\']*charset=([^"\'\s;]+)/i', $html, $matches)) {
      return strtoupper($matches[1]);
    }

    // Check XML declaration.
    if (preg_match('/<\?xml[^>]+encoding=["\']([^"\']+)/i', $html, $matches)) {
      return strtoupper($matches[1]);
    }

    return 'UTF-8';
  }

  /**
   * Extracts metadata from HTML before cleaning.
   *
   * @param string $html
   *   The raw HTML content.
   * @param string $url
   *   The source URL.
   *
   * @return \Drupal\ai_content_preparation_wizard\Model\DocumentMetadata
   *   The extracted metadata.
   */
  private function extractMetadata(string $html, string $url): \stdClass {
    $title = NULL;
    $author = NULL;
    $description = NULL;
    $language = NULL;
    $headings = [];

    // Suppress libxml errors for malformed HTML.
    $internalErrors = libxml_use_internal_errors(TRUE);

    $doc = new \DOMDocument();
    @$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOWARNING);

    libxml_clear_errors();
    libxml_use_internal_errors($internalErrors);

    // Extract title from <title> tag.
    $titleElements = $doc->getElementsByTagName('title');
    if ($titleElements->length > 0 && $titleElements->item(0) !== NULL) {
      $title = trim($titleElements->item(0)->textContent);
    }

    // Try og:title as fallback.
    if (empty($title)) {
      $title = $this->getMetaContent($doc, 'og:title');
    }

    // Extract meta tags.
    $metas = $doc->getElementsByTagName('meta');
    foreach ($metas as $meta) {
      if (!$meta instanceof \DOMElement) {
        continue;
      }
      $name = strtolower($meta->getAttribute('name') ?: $meta->getAttribute('property'));
      $content = $meta->getAttribute('content');

      switch ($name) {
        case 'author':
          if (empty($author)) {
            $author = $content;
          }
          break;

        case 'description':
        case 'og:description':
          if (empty($description)) {
            $description = $content;
          }
          break;
      }
    }

    // Extract language from html tag.
    $htmlElements = $doc->getElementsByTagName('html');
    if ($htmlElements->length > 0 && $htmlElements->item(0) instanceof \DOMElement) {
      $lang = $htmlElements->item(0)->getAttribute('lang');
      if (!empty($lang)) {
        $language = $lang;
      }
    }

    // Extract main headings (h1-h3) for structure.
    for ($level = 1; $level <= 3; $level++) {
      $headingElements = $doc->getElementsByTagName('h' . $level);
      foreach ($headingElements as $heading) {
        $headingText = trim($heading->textContent);
        if (!empty($headingText) && mb_strlen($headingText) < 200) {
          $headings[] = $headingText;
        }
        // Limit to 20 headings max.
        if (count($headings) >= 20) {
          break 2;
        }
      }
    }

    // Build custom properties.
    $customProperties = [
      'source_url' => $url,
    ];
    if (!empty($description)) {
      $customProperties['description'] = $description;
    }

    $metadata = new \stdClass();
    $metadata->title = $title ?: $this->getTitleFromUrl($url);
    $metadata->author = $author;
    $metadata->description = $description;
    $metadata->language = $language;
    $metadata->headings = $headings;
    $metadata->sourceUrl = $url;
    $metadata->wordCount = str_word_count(strip_tags($html));
    return $metadata;
  }

  /**
   * Gets meta content by property or name.
   *
   * @param \DOMDocument $doc
   *   The DOM document.
   * @param string $name
   *   The meta name or property to find.
   *
   * @return string|null
   *   The meta content or NULL.
   */
  private function getMetaContent(\DOMDocument $doc, string $name): ?string {
    $xpath = new \DOMXPath($doc);
    $query = sprintf(
      '//meta[@name="%s" or @property="%s"]/@content',
      $name,
      $name
    );
    $result = $xpath->query($query);

    if ($result !== FALSE && $result->length > 0 && $result->item(0) !== NULL) {
      return $result->item(0)->nodeValue;
    }

    return NULL;
  }

  /**
   * Cleans HTML by removing non-content elements and attributes.
   *
   * @param string $html
   *   The raw HTML content.
   *
   * @return string
   *   The cleaned HTML with only content elements.
   */
  private function cleanHtml(string $html): string {
    // Suppress libxml errors for malformed HTML.
    $internalErrors = libxml_use_internal_errors(TRUE);

    $doc = new \DOMDocument();
    @$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOWARNING);

    libxml_clear_errors();
    libxml_use_internal_errors($internalErrors);

    // Remove unwanted elements.
    $this->removeElements($doc);

    // Remove comments.
    $this->removeComments($doc);

    // Remove unwanted attributes.
    $this->removeAttributes($doc);

    // Remove empty elements.
    $this->removeEmptyElements($doc);

    // Try to extract main content.
    return $this->extractMainContent($doc);
  }

  /**
   * Removes specified elements from the DOM.
   *
   * @param \DOMDocument $doc
   *   The DOM document.
   */
  private function removeElements(\DOMDocument $doc): void {
    foreach (self::REMOVE_ELEMENTS as $tagName) {
      $elements = $doc->getElementsByTagName($tagName);
      // Build list first, as removing modifies the live NodeList.
      $toRemove = [];
      foreach ($elements as $element) {
        $toRemove[] = $element;
      }
      foreach ($toRemove as $element) {
        if ($element->parentNode !== NULL) {
          $element->parentNode->removeChild($element);
        }
      }
    }
  }

  /**
   * Removes HTML comments from the DOM.
   *
   * @param \DOMDocument $doc
   *   The DOM document.
   */
  private function removeComments(\DOMDocument $doc): void {
    $xpath = new \DOMXPath($doc);
    $comments = $xpath->query('//comment()');

    if ($comments !== FALSE) {
      $toRemove = [];
      foreach ($comments as $comment) {
        $toRemove[] = $comment;
      }
      foreach ($toRemove as $comment) {
        if ($comment->parentNode !== NULL) {
          $comment->parentNode->removeChild($comment);
        }
      }
    }
  }

  /**
   * Removes specified attributes and data-* attributes from all elements.
   *
   * @param \DOMDocument $doc
   *   The DOM document.
   */
  private function removeAttributes(\DOMDocument $doc): void {
    $xpath = new \DOMXPath($doc);
    $allElements = $xpath->query('//*');

    if ($allElements === FALSE) {
      return;
    }

    foreach ($allElements as $element) {
      if (!$element instanceof \DOMElement) {
        continue;
      }

      // Remove standard attributes.
      foreach (self::REMOVE_ATTRIBUTES as $attr) {
        if ($attr === 'data-*') {
          continue;
        }
        if ($element->hasAttribute($attr)) {
          $element->removeAttribute($attr);
        }
      }

      // Remove data-* attributes.
      $attributes = $element->attributes;
      $toRemove = [];
      for ($i = 0; $i < $attributes->length; $i++) {
        $attr = $attributes->item($i);
        if ($attr !== NULL && str_starts_with($attr->name, 'data-')) {
          $toRemove[] = $attr->name;
        }
      }
      foreach ($toRemove as $attrName) {
        $element->removeAttribute($attrName);
      }
    }
  }

  /**
   * Removes empty elements from the DOM.
   *
   * @param \DOMDocument $doc
   *   The DOM document.
   */
  private function removeEmptyElements(\DOMDocument $doc): void {
    // Elements that are allowed to be empty (void elements).
    $allowEmpty = ['br', 'hr', 'img', 'input', 'meta', 'link', 'area', 'base', 'col', 'embed', 'source', 'track', 'wbr'];

    $xpath = new \DOMXPath($doc);
    $changed = TRUE;
    $iterations = 0;

    // Iterate until no more empty elements are found (max 10 iterations).
    while ($changed && $iterations < 10) {
      $changed = FALSE;
      $iterations++;
      $allElements = $xpath->query('//*');

      if ($allElements === FALSE) {
        break;
      }

      $toRemove = [];
      foreach ($allElements as $element) {
        if (!$element instanceof \DOMElement) {
          continue;
        }
        $tagName = strtolower($element->nodeName);
        if (!in_array($tagName, $allowEmpty, TRUE)) {
          $textContent = trim($element->textContent);
          // Check if element is empty and has no meaningful children (img).
          if (empty($textContent) && !$this->hasImportantChildren($element)) {
            $toRemove[] = $element;
          }
        }
      }
      foreach ($toRemove as $element) {
        if ($element->parentNode !== NULL) {
          $element->parentNode->removeChild($element);
          $changed = TRUE;
        }
      }
    }
  }

  /**
   * Checks if an element has important children like images.
   *
   * @param \DOMElement $element
   *   The element to check.
   *
   * @return bool
   *   TRUE if the element has important children.
   */
  private function hasImportantChildren(\DOMElement $element): bool {
    $importantTags = ['img', 'table'];
    foreach ($importantTags as $tag) {
      if ($element->getElementsByTagName($tag)->length > 0) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Extracts main content from the DOM.
   *
   * @param \DOMDocument $doc
   *   The DOM document.
   *
   * @return string
   *   The extracted HTML content.
   */
  private function extractMainContent(\DOMDocument $doc): string {
    $xpath = new \DOMXPath($doc);

    // Priority order for content containers.
    $contentSelectors = [
      '//article',
      '//main',
      '//*[@role="main"]',
      '//*[contains(@class, "content")]',
      '//*[contains(@class, "post")]',
      '//*[contains(@class, "entry")]',
      '//*[contains(@class, "article")]',
      '//*[@id="content"]',
      '//*[@id="main"]',
    ];

    foreach ($contentSelectors as $query) {
      $result = $xpath->query($query);
      if ($result !== FALSE && $result->length > 0) {
        $element = $result->item(0);
        if ($element !== NULL) {
          $content = $doc->saveHTML($element);
          if ($content !== FALSE) {
            return $content;
          }
        }
      }
    }

    // Fall back to body content.
    $body = $doc->getElementsByTagName('body');
    if ($body->length > 0 && $body->item(0) !== NULL) {
      $content = $doc->saveHTML($body->item(0));
      if ($content !== FALSE) {
        return $content;
      }
    }

    // Last resort: return entire document.
    $content = $doc->saveHTML();
    return $content !== FALSE ? $content : '';
  }

  /**
   * Converts cleaned HTML to markdown.
   *
   * @param string $html
   *   The cleaned HTML content.
   *
   * @return string
   *   The markdown representation.
   */
  private function htmlToMarkdown(string $html): string {
    // Suppress libxml errors.
    $internalErrors = libxml_use_internal_errors(TRUE);

    $doc = new \DOMDocument();
    @$doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOWARNING);

    libxml_clear_errors();
    libxml_use_internal_errors($internalErrors);

    $markdown = $this->nodeToMarkdown($doc->documentElement);

    return $markdown;
  }

  /**
   * Recursively converts a DOM node to markdown.
   *
   * @param \DOMNode|null $node
   *   The DOM node to convert.
   * @param int $listLevel
   *   Current list nesting level.
   *
   * @return string
   *   The markdown representation.
   */
  private function nodeToMarkdown(?\DOMNode $node, int $listLevel = 0): string {
    if ($node === NULL) {
      return '';
    }

    // Process text nodes.
    if ($node->nodeType === XML_TEXT_NODE) {
      return $node->textContent;
    }

    // Skip non-element nodes but process children.
    if ($node->nodeType !== XML_ELEMENT_NODE) {
      $output = '';
      foreach ($node->childNodes as $child) {
        $output .= $this->nodeToMarkdown($child, $listLevel);
      }
      return $output;
    }

    $tagName = strtolower($node->nodeName);

    // Process children first for most elements.
    $childContent = '';
    foreach ($node->childNodes as $child) {
      $childContent .= $this->nodeToMarkdown($child, $listLevel);
    }
    $childContent = trim($childContent);

    switch ($tagName) {
      // Headers.
      case 'h1':
        return empty($childContent) ? '' : "# {$childContent}\n\n";

      case 'h2':
        return empty($childContent) ? '' : "## {$childContent}\n\n";

      case 'h3':
        return empty($childContent) ? '' : "### {$childContent}\n\n";

      case 'h4':
        return empty($childContent) ? '' : "#### {$childContent}\n\n";

      case 'h5':
        return empty($childContent) ? '' : "##### {$childContent}\n\n";

      case 'h6':
        return empty($childContent) ? '' : "###### {$childContent}\n\n";

      // Paragraphs.
      case 'p':
        return empty($childContent) ? '' : "{$childContent}\n\n";

      // Divs - just return content with newline.
      case 'div':
        return empty($childContent) ? '' : "{$childContent}\n";

      // Line breaks.
      case 'br':
        return "\n";

      case 'hr':
        return "\n---\n\n";

      // Emphasis.
      case 'strong':
      case 'b':
        return empty($childContent) ? '' : "**{$childContent}**";

      case 'em':
      case 'i':
        return empty($childContent) ? '' : "*{$childContent}*";

      case 'u':
        return $childContent;

      case 's':
      case 'strike':
      case 'del':
        return empty($childContent) ? '' : "~~{$childContent}~~";

      // Links.
      case 'a':
        if ($node instanceof \DOMElement) {
          $href = $node->getAttribute('href');
          if (!empty($href) && !empty($childContent)) {
            // Skip javascript and anchor-only links.
            if (!str_starts_with($href, 'javascript:') && $href !== '#') {
              return "[{$childContent}]({$href})";
            }
          }
        }
        return $childContent;

      // Images - only include if they have meaningful alt text.
      case 'img':
        if ($node instanceof \DOMElement) {
          $alt = trim($node->getAttribute('alt'));
          $src = $node->getAttribute('src');
          // Only include images with alt text longer than 3 chars.
          if (!empty($src) && !empty($alt) && strlen($alt) > 3) {
            return "![{$alt}]({$src})";
          }
        }
        return '';

      // Unordered lists.
      case 'ul':
        $items = '';
        foreach ($node->childNodes as $child) {
          if ($child->nodeName === 'li') {
            $itemContent = trim($this->nodeToMarkdown($child, $listLevel + 1));
            if (!empty($itemContent)) {
              $indent = str_repeat('  ', $listLevel);
              $items .= "{$indent}- {$itemContent}\n";
            }
          }
        }
        return empty($items) ? '' : $items . "\n";

      // Ordered lists.
      case 'ol':
        $items = '';
        $counter = 1;
        foreach ($node->childNodes as $child) {
          if ($child->nodeName === 'li') {
            $itemContent = trim($this->nodeToMarkdown($child, $listLevel + 1));
            if (!empty($itemContent)) {
              $indent = str_repeat('  ', $listLevel);
              $items .= "{$indent}{$counter}. {$itemContent}\n";
              $counter++;
            }
          }
        }
        return empty($items) ? '' : $items . "\n";

      // List items - just return content.
      case 'li':
        return $childContent;

      // Code.
      case 'code':
        return empty($childContent) ? '' : "`{$childContent}`";

      case 'pre':
        return empty($childContent) ? '' : "```\n{$childContent}\n```\n\n";

      // Blockquotes.
      case 'blockquote':
        if (empty($childContent)) {
          return '';
        }
        $lines = explode("\n", $childContent);
        $quotedLines = array_map(fn($line) => "> " . $line, $lines);
        return implode("\n", $quotedLines) . "\n\n";

      // Tables.
      case 'table':
        return $this->tableToMarkdown($node);

      // Skip table elements processed in tableToMarkdown.
      case 'thead':
      case 'tbody':
      case 'tfoot':
      case 'tr':
      case 'th':
      case 'td':
        return '';

      // Definition lists.
      case 'dl':
        $content = '';
        foreach ($node->childNodes as $child) {
          if ($child->nodeName === 'dt') {
            $dtContent = trim($this->nodeToMarkdown($child, $listLevel));
            if (!empty($dtContent)) {
              $content .= "**{$dtContent}**\n";
            }
          }
          elseif ($child->nodeName === 'dd') {
            $ddContent = trim($this->nodeToMarkdown($child, $listLevel));
            if (!empty($ddContent)) {
              $content .= ": {$ddContent}\n\n";
            }
          }
        }
        return $content;

      case 'dt':
      case 'dd':
        return $childContent;

      // Address.
      case 'address':
        return empty($childContent) ? '' : "*{$childContent}*\n\n";

      // Figures.
      case 'figure':
        return "{$childContent}\n";

      case 'figcaption':
        return empty($childContent) ? '' : "*{$childContent}*\n";

      // Semantic containers - just return their content.
      case 'article':
      case 'section':
      case 'main':
      case 'body':
      case 'html':
      case 'span':
        return $childContent;

      // Default: just return content.
      default:
        return $childContent;
    }
  }

  /**
   * Converts a table element to markdown.
   *
   * @param \DOMNode $table
   *   The table element.
   *
   * @return string
   *   The markdown table.
   */
  private function tableToMarkdown(\DOMNode $table): string {
    $rows = [];
    $headerRow = NULL;
    $maxCols = 0;

    // Extract all rows.
    $trElements = [];
    $this->findElements($table, 'tr', $trElements);

    foreach ($trElements as $tr) {
      $cells = [];
      $isHeader = FALSE;
      foreach ($tr->childNodes as $cell) {
        if ($cell->nodeName === 'th') {
          $isHeader = TRUE;
          $cellContent = trim($this->getTextContent($cell));
          $cellContent = str_replace('|', '\\|', $cellContent);
          $cellContent = preg_replace('/\s+/', ' ', $cellContent);
          $cells[] = $cellContent;
        }
        elseif ($cell->nodeName === 'td') {
          $cellContent = trim($this->getTextContent($cell));
          $cellContent = str_replace('|', '\\|', $cellContent);
          $cellContent = preg_replace('/\s+/', ' ', $cellContent);
          $cells[] = $cellContent;
        }
      }
      if (!empty($cells)) {
        if ($headerRow === NULL && $isHeader) {
          $headerRow = $cells;
        }
        else {
          $rows[] = $cells;
        }
        $maxCols = max($maxCols, count($cells));
      }
    }

    if (empty($rows) && $headerRow === NULL) {
      return '';
    }

    // If no header row detected, use first row as header.
    if ($headerRow === NULL && !empty($rows)) {
      $headerRow = array_shift($rows);
    }

    // Normalize column count.
    if ($headerRow !== NULL) {
      while (count($headerRow) < $maxCols) {
        $headerRow[] = '';
      }
    }

    $output = '';

    // Header row.
    if ($headerRow !== NULL) {
      $output .= '| ' . implode(' | ', $headerRow) . " |\n";
      $output .= '|' . str_repeat(' --- |', count($headerRow)) . "\n";
    }

    // Data rows.
    foreach ($rows as $row) {
      while (count($row) < $maxCols) {
        $row[] = '';
      }
      $output .= '| ' . implode(' | ', $row) . " |\n";
    }

    return empty($output) ? '' : $output . "\n";
  }

  /**
   * Recursively finds elements by tag name.
   *
   * @param \DOMNode $node
   *   The parent node.
   * @param string $tagName
   *   The tag name to find.
   * @param array $results
   *   Array to store results (passed by reference).
   */
  private function findElements(\DOMNode $node, string $tagName, array &$results): void {
    foreach ($node->childNodes as $child) {
      if ($child->nodeName === $tagName) {
        $results[] = $child;
      }
      else {
        $this->findElements($child, $tagName, $results);
      }
    }
  }

  /**
   * Gets plain text content from a node.
   *
   * @param \DOMNode $node
   *   The DOM node.
   *
   * @return string
   *   The text content.
   */
  private function getTextContent(\DOMNode $node): string {
    return $node->textContent;
  }

  /**
   * Normalizes whitespace in markdown output.
   *
   * @param string $markdown
   *   The raw markdown.
   *
   * @return string
   *   The normalized markdown.
   */
  private function normalizeWhitespace(string $markdown): string {
    // Remove excessive blank lines (more than 2 newlines -> 2).
    $markdown = preg_replace('/\n{3,}/', "\n\n", $markdown);

    // Trim whitespace from lines.
    $lines = explode("\n", $markdown);
    $lines = array_map('rtrim', $lines);
    $markdown = implode("\n", $lines);

    // Remove leading/trailing whitespace.
    return trim($markdown);
  }

  /**
   * Converts a URL to a suitable filename.
   *
   * @param string $url
   *   The URL.
   *
   * @return string
   *   A filename derived from the URL.
   */
  private function urlToFilename(string $url): string {
    $parsed = parse_url($url);
    $host = $parsed['host'] ?? 'unknown';
    $path = $parsed['path'] ?? '';

    // Clean the path.
    $path = preg_replace('/[^a-zA-Z0-9\-_]/', '-', $path);
    $path = preg_replace('/-+/', '-', $path);
    $path = trim($path, '-');

    if (empty($path)) {
      return $host . '.webpage';
    }

    // Limit length.
    if (strlen($path) > 50) {
      $path = substr($path, 0, 50);
    }

    return $host . '-' . $path . '.webpage';
  }

  /**
   * Generates a title from a URL when no title is found.
   *
   * @param string $url
   *   The URL.
   *
   * @return string
   *   A title derived from the URL.
   */
  private function getTitleFromUrl(string $url): string {
    $parsed = parse_url($url);
    $host = $parsed['host'] ?? '';
    $path = $parsed['path'] ?? '';

    // Clean up the path for display.
    $path = trim($path, '/');
    if (!empty($path)) {
      $path = str_replace(['/', '-', '_'], ' ', $path);
      $path = ucwords($path);
      return $path . ' - ' . $host;
    }

    return $host;
  }

}
