<?php

namespace splitbrain\blogrng;

use DOMWrap\Document;
use Wa72\Url\Url;

class Mastodon
{

    /**
     * Find the Mastodon profile associated with the given homepage
     *
     * Requires a rel=me link to the profile and a backlink from that profile
     *
     * @param string $homepage
     * @return string empty string if no profile was found
     */
    public function getProfile($homepage)
    {
        $html = $this->httpget($homepage);

        // simplify homepage url
        $homepage = new Url($homepage);
        $homepage = $homepage->getHost() . rtrim($homepage->getPath(), '/');

        $dom = new Document();
        $dom->html($html);
        $links = $dom->find('a[rel=me]');

        foreach ($links as $link) {
            $href = $link->attr('href') . '.json';
            $json = $this->httpget($href);
            $data = json_decode($json, true);

            if ($data && isset($data['attachment'])) foreach ($data['attachment'] as $attachment) {
                if ($attachment['type'] === 'PropertyValue' && (stripos($attachment['value'], $homepage) !== false)) {
                    $server = new Url($data['url']);
                    return trim($server->getPath(),'/') . '@' . $server->getHost();
                }
            }
        }

        return '';
    }

    /**
     * Simple HTTP client
     *
     * @param string $url
     * @param array $headers
     * @return string
     */
    public function httpget($url, $headers = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        $output = curl_exec($ch);
        curl_close($ch);
        return $output;
    }

    /**
     * Post to Mastodon
     *
     * @param string $status Status to post
     * @param string $instance Mastodon instance
     * @param string $token Mastodon Access Token
     * @return mixed
     */
    public function postStatus($status, $instance, $token)
    {
        $headers = [
            "Authorization: Bearer $token"
        ];

        $status_data = [
            "status" => $status,
            "language" => "en",
            "visibility" => "public"
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$instance/api/v1/statuses");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $status_data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        $output = curl_exec($ch);
        curl_close ($ch);

        return json_decode($output, true);
    }

    /**
     * Post a single item to Mastodon
     *
     * @param string[] $post
     * @param string $instance
     * @param string $token
     * @return mixed
     */
    public function postItem($post, $instance, $token)
    {

        $text = $post['itemtitle'];
        $text .= ' (' . date('Y-m-d', $post['published']) . ')';
        if ($post['mastodon']) {
            $text .= ' by ' . $post['mastodon'];
        }
        $text .= "\n\n" . Controller::campaignURL($post['itemurl'], 'mastodon');

        $text .= "\n\n🎲 " . $post['feedid'] . '-' . $post['itemid'];
        $text .= "\n#blog #blogging #blogpost #random";

        return $this->postStatus($text, $instance, $token);
    }
}
