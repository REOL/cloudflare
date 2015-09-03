<?php
/**
 * CloudFlare - A custom extension developped to easily communicate with the CloudFlare API.
 * This extension will implement all the API calls presented at
 *      https://www.cloudflare.com/docs/client-api.html#s3.4
 * The credentials to communicate with the API should be saved in the application's parameters
 * as follows:
 *  Yii::$app->params['cloudflare'] = [
 *      "cloudflare_auth_email" => "admin@comain.com",
 *      "cloudflare_auth_key"   => "YOUR_AUTHORIZATION_TOKEN_HERE",
 *  ];
 *
 * @link http:s//github.com/REOL/cloudflare.git
 * @license http://www.gnu.org/licenses/lgpl.html LGPL v3 or later
 * @author Renaud Tilte <rtilte@reol.com>
 */

namespace Cloudflare;

use Yii;
use Cloudflare\Exception\AuthenticationException;
use Cloudflare\Exception\InvalidInputException;
use Cloudflare\Exception\MaxAPICallException;
use Cloudflare\Exception\APIException;

class Client
{
    /**
     * Current version number of DeviceDetector
     */
    const VERSION = '0.0.1';

    /**
     * API endpoint that will be used to GET/POST the DNS record data
     */
    const API_ENDPOINT = 'https://www.cloudflare.com/api_json.html';

    /**
     * Holds the DNS records for a given domain
     * @var array
     */
    protected $domains;

    /**
     * Constructor
     *
     * @param string $userAgent  UA to parse
     */
    public function __construct()
    {
        $this->domains = [];
    }

    /**
     * This function is used to retrieve all the DNS records for a particular zone,
     * including the main domain name and its subdomains. By default, only the A records
     * are kept, but the record type desired can be specified in the parameters, as well
     * as the page in case there are more than 180 records.
     *
     * @param string $domain_name The domain name and its subdomains. If a subdomain is passed as domain name, only its own subdomains will be returned
     * @param string $record_type The record type desired
     * @param integer $offset The offset used for pagination. In facts, since this function is recursive, there's no need to set this parameter in a direct call
     * @return array $res The array of DNS records
     */
    public function getDNSRecords($domain_name, $record_type = null, $offset = null)
    {
        // rec_load_all to retrieve all the DNS records for the given domain
        $action = "rec_load_all";

        // Verify that the type of the new DNS record
        if ($record_type != null) {
            $record_type = strtoupper($record_type);
            if (!in_array($record_type, ['A', 'CNAME', 'MX', 'TXT', 'SPF', 'AAAA', 'NS', 'SRV', 'LOC'])) {
                throw new InvalidInputException("error", "The record type is not valid");
            }
        }

        // parse the domain name to only keep the name and the tld to use the CloudFlare API
        $mainDomain = self::extractDomain($domain_name);

        // required parameters for the action, transformed as a query string
        $parameters = [
            'a' => $action,
            'tkn' => Yii::$app->params['cloudflare']['cloudflare_auth_key'],
            'email' => Yii::$app->params['cloudflare']['cloudflare_auth_email'],
            'z' => $mainDomain
        ];
        if ($offset) {
            $parameters["o"] = $offset;
        }
        $queryString = "?" . http_build_query($parameters);

        // cURL call to the API endpoint
        $jsonResult = self::sendCurlRequest($queryString);

        // Analyse the response
        $fullResult = json_decode($jsonResult);
        $res = [];

        // Check if there is an error or not
        if ($fullResult->result != 'success') {
            $this->handleError($fullResult->result, $fullResult->msg, isset($fullResult->err_code) ? $fullResult->err_code : null);
        } else {
            $response = $fullResult->response;
            $domains = $response->recs->objs;
            foreach ($domains as $domain) {
                // We only keep the records of the corresponding type for the (sub)domain passed in the parameters
                if (!array_key_exists($domain->name, $this->domains) && strpos($domain->name, $domain_name) !== false) {
                    if ($record_type == null || ($record_type != null && $domain->type == $record_type)) {
                        $this->domains[$domain->name] = $this->extractDomainData($domain);
                    }
                }
            }
            // By default, CloudFlare only returns the first 180 first records, load the next ones in case there are some
            if ($response->recs->has_more) {
                $this->getARecords($domain_name, $response->recs->count);
            }
        }

        return empty($res) ? $this->domains : $res;
    }

    /**
     * This function is used to add a new entry in the DNS Zone file for a given domain,
     * or even add a new domain to the CloudFlare records.
     *
     * @param string $zone The main domain in which the new entry will be registered
     * @param string $content The corresponding IP address that matches the domain name
     * @param string $recordName The DNS record name, or the subdomain name if a new sub domain is registered
     * @param string $type The DNS record Type (A/CNAME/MX/TXT/SPF/AAAA/NS/SRV/LOC)
     * @param integer $ttl The default TTL for the DNS record (1 = auto, else between 120 and 86400 sec)
     * @param integer $prio The priority for MX records only
     * @return array $res The array containing the new DNS record properties
     */
    public function addDNSRecord($recordName, $content, $type = 'A', $ttl = '1', $prio = 0)
    {
        // Check input first, if the $zone or $content are empty, it's useless sending an API call that will fail anyway
        if ($recordName == null || $content == null) {
            throw new InvalidInputException("error", "Record Name parameter or IP address (content) is missing");
        }

        // Verify the type of the new DNS record to add
        $type = strtoupper($type);
        if (!in_array($type, ['A', 'CNAME', 'MX', 'TXT', 'SPF', 'AAAA', 'NS', 'SRV', 'LOC'])) {
            throw new InvalidInputException("error", "The new record does not have a valid type");
        }

        // Retrieve the main domain name and the subdomain from the record name
        $mainDomain = strtolower(self::extractDomain($recordName));
        $subDomain = strtolower(self::extractSubdomain($recordName));

        // Check that the $content is a valid IP address
        if (filter_var($content, FILTER_VALIDATE_IP) === false) {
            throw new InvalidInputException("error", "The new record does not have a valid IP address");
        }

        // rec_new to add a new domain or a new subdomain
        $action = "rec_new";

        // required parameters for the action, transformed as a query string
        $parameters = [
            'a' => $action,
            'tkn' => Yii::$app->params['cloudflare']['cloudflare_auth_key'],
            'email' => Yii::$app->params['cloudflare']['cloudflare_auth_email'],
            'z' => $mainDomain,
            'type' => $type,
            'name' => $subDomain,
            'content' => $content,
            'ttl' => $ttl,
        ];
        if ($prio) {
            $parameters["prio"] = $prio;
        }
        $queryString = "?" . http_build_query($parameters);

        // cURL call to the API endpoint
        $jsonResult = self::sendCurlRequest($queryString);

        // Analyse the response
        $fullResult = json_decode($jsonResult);

        // Check if there is an error or not
        if ($fullResult->result != 'success') {
            $this->handleError($fullResult->result, $fullResult->msg, isset($fullResult->err_code) ? $fullResult->err_code : null);
        }

        // If no errors, get the DNS records properties from the getDNSRecords function
        return $this->getDNSRecords($recordName);
    }

    /**
     * This function is used to delete a specific DNS record.
     * If the $domain_name references a main domain, nothing will be deleted for security reasons.
     * If the $record_type is specified, only the DNS record for that type will be deleted,
     * else all DNS records (A, CNAME, MX etc...) for that subdomain will be deleted.
     * @param string $domain_name The domain name for which the DNS records need to be deleted
     * @param string $record_type The record type that needs to be deleted
     */
    public function deleteDNSRecords($domain_name, $record_type = null)
    {
        // Verify the type of the new DNS record to remove
        if ($record_type != null) {
            $record_type = strtoupper($record_type);
            if (!in_array($record_type, ['A', 'CNAME', 'MX', 'TXT', 'SPF', 'AAAA', 'NS', 'SRV', 'LOC'])) {
                throw new InvalidInputException("error", "The new record does not have a valid type");
            }
        }

        // Check that the user is not trying to delete a main domain (do that through the CloudFlare console instead)
        $mainDomain = self::extractDomain($domain_name);
        if (strtolower($mainDomain) == $domain_name && ($record_type == 'A' || $record_type == null)) {
            throw new InvalidInputException("error", "It is forbidden to delete the A entry for a main domain. Please select a subdomain or another record type");
        }

        // rec_delete to add a new domain or a new subdomain
        $action = "rec_delete";

        $domains = $this->getDNSRecords($domain_name, $record_type);
        foreach ($domains as $domain) {
            $parameters = [
                'a' => $action,
                'tkn' => Yii::$app->params['cloudflare']['cloudflare_auth_key'],
                'email' => Yii::$app->params['cloudflare']['cloudflare_auth_email'],
                'z' => $mainDomain,
                'id' => $domain['rec_id']
            ];
            $queryString = "?" . http_build_query($parameters);

            // cURL call to the API endpoint
            $jsonResult = self::sendCurlRequest($queryString);

            // Analyse the response
            $fullResult = json_decode($jsonResult);

            // Check if there is an error or not
            if ($fullResult->result != 'success') {
                $this->handleError($fullResult->result, $fullResult->msg, isset($fullResult->err_code) ? $fullResult->err_code : null);
            }
        }

        // If no errors, just return an empty array since the record is supposed to be deleted (there might be some delay so we can't use the getDNSRecords function that MAY return something)
        return [];
    }

    /**
     * This function is used to format correctly the domain data from the API response
     * and only keep the things we need (rec_id, type, name, ttl and content)
     * @param array $domain A JSON object containing the data about a specific domain retrieved from the API
     * @return array $res An associative array containing only the data needed
     */
    private function extractDomainData($domain)
    {
        // We will only keep the rec_id, type, name, ttl and content
        $res = [
            "rec_id" => $domain->rec_id,
            "type" => $domain->type,
            "domain_name" => $domain->name,
            "ttl" => $domain->ttl
        ];
        if ($domain->content) {
            $res["content"] = $domain->content;
        }

        return $res;
    }

    /**
     * This function is used to extract the sub domains of a given URL
     *
     * @param string $domain The complete URL
     * @return string $subdomain The complete subdomain string (subdomain1.subdomain2.etc)
     */
    private static function extractSubdomain($domain)
    {
        $subdomains = $domain;
        $domain = self::extractDomain($subdomains);
        $subdomains = rtrim(strstr($subdomains, $domain, true), '.');
        return $subdomains;
    }

    /**
     * This function is used to extract the main domain name of a given URL (main domain + TLD)
     *
     * @param string $domain The complete URL
     * @return string $domain The main domain name with its TLD
     */
    private static function extractDomain($domain)
    {
        if (preg_match("/(?P<domain>[a-z0-9][a-z0-9\-]{1,63}\.[a-z\.]{2,6})$/i", $domain, $matches)) {
            return $matches['domain'];
        } else {
            return $domain;
        }
    }

    /**
     * This function is used to send a cURL request to the Cloudflare API.
     * The only thing that changes for all API callse is the parameters of the GET request.
     *
     * @param string $queryString The stringified parameters (key1=value1&key2=value2&keyN=valueN)
     * @return array $jsonResult The object (array) representing the JSON result
     */
    private static function sendCurlRequest($queryString = '')
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::API_ENDPOINT . $queryString);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $jsonResult = curl_exec($ch);
        curl_close($ch);

        return $jsonResult;
    }

    private function handleError($result, $message, $error_code)
    {
        if ($error_code != null) {
            switch ($error_code) {
                case "E_UNAUTH":
                    throw new AuthenticationException($result, $message);
                    break;
                case "E_INVLDINPUT":
                    throw new InvalidInputException($result, $message);
                    break;
                case "E_MAXAPI":
                    throw new MaxAPICallException($result, $message);
                    break;
            }
        } else {
            throw new APIException($message);
        }
    }
}
