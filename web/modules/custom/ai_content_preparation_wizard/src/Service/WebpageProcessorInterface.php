<?php

declare(strict_types=1);

namespace Drupal\ai_content_preparation_wizard\Service;

use Drupal\ai_content_preparation_wizard\Model\ProcessedWebpage;

/**
 * Interface for processing web pages into markdown documents.
 *
 * This service fetches web pages via HTTP, cleans the HTML to extract
 * main content, and converts it to markdown format suitable for
 * AI processing.
 */
interface WebpageProcessorInterface {

  /**
   * Processes a single URL and extracts its content.
   *
   * Fetches the webpage, cleans the HTML to remove navigation,
   * scripts, and other non-content elements, then converts
   * the remaining content to markdown.
   *
   * @param string $url
   *   The URL to fetch and process.
   *
   * @return \Drupal\ai_content_preparation_wizard\Model\ProcessedWebpage
   *   The processed webpage with extracted content.
   *
   * @throws \Drupal\ai_content_preparation_wizard\Exception\DocumentProcessingException
   *   When the URL cannot be fetched or processed.
   */
  public function processUrl(string $url): ProcessedWebpage;

  /**
   * Processes multiple URLs and extracts their content.
   *
   * Processes each URL individually and returns an array of
   * processed webpages. Failed URLs will have error information
   * in the metadata.
   *
   * @param array<string> $urls
   *   Array of URLs to fetch and process.
   *
   * @return array<\Drupal\ai_content_preparation_wizard\Model\ProcessedWebpage>
   *   Array of processed webpages, one per successfully processed URL.
   *   Failed URLs are skipped with logged errors.
   */
  public function processUrls(array $urls): array;

  /**
   * Validates whether a string is a valid, fetchable URL.
   *
   * Checks URL format and scheme (http/https only).
   * Does not actually fetch the URL to validate accessibility.
   *
   * @param string $url
   *   The URL to validate.
   *
   * @return bool
   *   TRUE if the URL is valid and can be attempted for fetching.
   */
  public function isValidUrl(string $url): bool;

}
