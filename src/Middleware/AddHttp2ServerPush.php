<?php

namespace JacobBennett\Http2ServerPush\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\DomCrawler\Crawler;

class AddHttp2ServerPush
{

    /**
     * The DomCrawler instance.
     *
     * @var \Symfony\Component\DomCrawler\Crawler
     */
    protected $crawler;

    /**
     * Handle an incoming request.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Closure $next
     *
     * @return mixed
     */
    public function handle(Request $request, Closure $next, $limit = null)
    {
        $response = $next($request);

        if ($response->isRedirection() || !$response instanceof Response || $request->isJson() || explode('/', $request->server('SERVER_PROTOCOL'))['1'] < 2) {
            return $response;
        }

        $this->generateAndAttachLinkHeaders($response, $limit);

        return $response;
    }

    /**
     * @param \Illuminate\Http\Response $response
     *
     * @return $this
     */
    protected function generateAndAttachLinkHeaders(Response $response, $limit = null)
    {
        $headers = $this->fetchLinkableNodes($response)
            ->map(function ($url) {
                return $this->buildLinkHeaderString($url);
            })
            ->take($limit)
            ->implode(',');

        if (!empty(trim($headers))) {
            $this->addLinkHeader($response, $headers);
        }

        return $this;
    }

    /**
     * Get the DomCrawler instance.
     *
     * @param \Illuminate\Http\Response $response
     *
     * @return \Symfony\Component\DomCrawler\Crawler
     */
    protected function getCrawler(Response $response)
    {
        if ($this->crawler) {
            return $this->crawler;
        }

        return $this->crawler = new Crawler($response->getContent());
    }

    /**
     * Get all nodes we are interested in pushing.
     *
     * @param \Illuminate\Http\Response $response
     *
     * @return \Illuminate\Support\Collection
     */
    protected function fetchLinkableNodes($response)
    {
        $crawler = $this->getCrawler($response);

        $links = collect($crawler->filter('link, script[src], img[src]')->extract(['src', 'href']));
        return $links->flatten(1)->filter()->unique();
    }

    /**
     * Build out header string based on asset extension.
     *
     * @param string $url
     *
     * @return string
     */
    private function buildLinkHeaderString($url)
    {
        $linkTypeMap = [
            '.CSS'  => 'style',
            '.JS'   => 'script',
            '.BMP'  => 'image',
            '.GIF'  => 'image',
            '.JPG'  => 'image',
            '.JPEG' => 'image',
            '.PNG'  => 'image',
            '.TIFF' => 'image',
        ];

        $type = collect($linkTypeMap)->first(function ($type, $extension) use ($url) {
            return str_contains(strtoupper($url), $extension);
        });

        return is_null($type) ? null : "<{$url}>; rel=preload; as={$type}";

    }

    /**
     * Add Link Header
     *
     * @param \Illuminate\Http\Response $response
     *
     * @param $link
     */
    private function addLinkHeader(Response $response, $link)
    {
        $response->header('Link', $link);
    }

}
