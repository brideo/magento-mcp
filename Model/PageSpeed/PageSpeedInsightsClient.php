<?php
/**
 * UpturnStudio_Mcp
 */
declare(strict_types=1);

namespace UpturnStudio\Mcp\Model\PageSpeed;

use Magento\Framework\HTTP\Client\CurlFactory;
use Psr\Log\LoggerInterface;
use UpturnStudio\Mcp\Model\Config;

/**
 * UpturnStudio_Mcp
 *
 * Calls Google's PageSpeed Insights API for a given URL. Unlike GraphQlExecutor, this is a
 * genuine external call - normal DNS resolution and TLS verification, no loopback tricks -
 * and needs a much longer timeout, since a live Lighthouse run commonly takes 20-30 seconds.
 */
class PageSpeedInsightsClient
{
    private const API_URL = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
    private const TIMEOUT_SECONDS = 60;

    private const FIELD_METRICS = [
        'largestContentfulPaint' => 'LARGEST_CONTENTFUL_PAINT_MS',
        'cumulativeLayoutShift' => 'CUMULATIVE_LAYOUT_SHIFT_SCORE',
        'interactionToNextPaint' => 'INTERACTION_TO_NEXT_PAINT',
        'firstContentfulPaint' => 'FIRST_CONTENTFUL_PAINT_MS',
        'timeToFirstByte' => 'EXPERIMENTAL_TIME_TO_FIRST_BYTE',
    ];

    private const LAB_AUDITS = [
        'largestContentfulPaint' => 'largest-contentful-paint',
        'cumulativeLayoutShift' => 'cumulative-layout-shift',
        'totalBlockingTime' => 'total-blocking-time',
        'speedIndex' => 'speed-index',
        'firstContentfulPaint' => 'first-contentful-paint',
    ];

    /**
     * @param CurlFactory $curlFactory
     * @param Config $config
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param string $url The absolute URL to analyze
     * @param string $strategy "mobile" or "desktop"
     * @return array{fieldData: array|null, labData: array, performanceScore: float|null}
     * @throws PageSpeedInsightsException
     */
    public function analyze(string $url, string $strategy = 'mobile'): array
    {
        $params = ['url' => $url, 'strategy' => $strategy, 'category' => 'PERFORMANCE'];
        $apiKey = $this->config->getPageSpeedApiKey();
        if ($apiKey !== null) {
            $params['key'] = $apiKey;
        }

        try {
            $curl = $this->curlFactory->create();
            $curl->setTimeout(self::TIMEOUT_SECONDS);
            $curl->get(self::API_URL . '?' . http_build_query($params));

            $status = $curl->getStatus();
            $decoded = json_decode((string) $curl->getBody(), true);

            if ($status !== 200 || !is_array($decoded)) {
                $message = is_array($decoded) ? ($decoded['error']['message'] ?? null) : null;
                $this->logger->error(sprintf(
                    'UpturnStudio_Mcp: PageSpeed Insights call failed - status=%d body=%s',
                    $status,
                    substr((string) $curl->getBody(), 0, 500)
                ));
                throw new PageSpeedInsightsException($message ?? 'PageSpeed Insights request failed.');
            }

            return [
                'fieldData' => $this->extractFieldData($decoded),
                'labData' => $this->extractLabData($decoded),
                'performanceScore' => $decoded['lighthouseResult']['categories']['performance']['score'] ?? null,
            ];
        } catch (PageSpeedInsightsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error(
                'UpturnStudio_Mcp: PageSpeed Insights call failed - ' . $e->getMessage(),
                ['exception' => $e]
            );
            throw new PageSpeedInsightsException('PageSpeed Insights request failed.', 0, $e);
        }
    }

    /**
     * Field data (real user CrUX metrics) is entirely absent from the response when Google
     * doesn't have enough real-world traffic for this exact URL to report on - that's a normal,
     * common case for lower-traffic pages, not an error.
     *
     * @param array $response
     * @return array|null
     */
    private function extractFieldData(array $response): ?array
    {
        $metrics = $response['loadingExperience']['metrics'] ?? null;
        if (!is_array($metrics)) {
            return null;
        }

        $result = [];
        foreach (self::FIELD_METRICS as $key => $metricName) {
            if (isset($metrics[$metricName])) {
                $result[$key] = [
                    'percentile' => $metrics[$metricName]['percentile'] ?? null,
                    'category' => $metrics[$metricName]['category'] ?? null,
                ];
            }
        }

        return $result;
    }

    /**
     * @param array $response
     * @return array
     */
    private function extractLabData(array $response): array
    {
        $audits = $response['lighthouseResult']['audits'] ?? [];

        $result = [];
        foreach (self::LAB_AUDITS as $key => $auditName) {
            if (isset($audits[$auditName])) {
                $result[$key] = [
                    'value' => $audits[$auditName]['numericValue'] ?? null,
                    'displayValue' => $audits[$auditName]['displayValue'] ?? null,
                ];
            }
        }

        return $result;
    }
}
