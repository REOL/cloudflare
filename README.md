Cloudflare
==============

This extension implements a few functionalities of the Cloudflare API v1. Even if this API is deprecated and it is recommended to uyse the API v4, it is (and will be) still supported and allows the use of GET requests only. This extension in its current version allows a user to retrieve the main information about a specific domain registered on a Cloudflare account, add a new domain (or any DNS record) or delete a subdomain (or any DNS record except main domains, for security reasons).

## Installation

Using the Cloudflare client with composer is quite easy. Just add the following to your composer.json file:

```php
    "require-dev": {
        ...
        "reolservices/cloudflare": "*",
        ...
    }
```

Then run composer update to get the extension integrated to your application.

## Usage

Just add reolservices/cloudflare to your projects requirements. You will need to add your Cloudflare credentials to your configuration as follows:

```php

$config = [
    ...
    'params' => [
        ...
        'cloudflare' => [
            "cloudflare_auth_email" => "email@domain.com",
            "cloudflare_auth_key" => "YOUR_AUTH_KEY_HERE",
        ],
        ...
    ],
    ...
];
```

And use some code like this one:

```php
    // Initialize the client
    $cfClient = new \Cloudflare\Client();

    // Retrieve the lise of all DNS records for a given domain that is registered in your Cloudflare account
    $domains = $cfClient->getDNSRecords('example.com');

    // Retrieve the list of 'A' records for a given subdomain
    $subDomains = $cfClient->getDNSRecords('sub.example.com', 'A');

    // Add a new Subdomain (A record) to your Cloudflare DNS records
    $newDomain = $cfClient->addDNSRecord('new.sub.example.com', '1.2.3.4');

    // Add a new CNAME / MX record to your Cloudflare DNS records
    $newCNAME = $cfClient->addDNSRecord('other.sub.example.com', 'new.sub.example.com', 'CNAME');
    $newMX = $cfClient->addDNSRecord('new.sub.example.com', '1.2.3.4', 'MX');

    // Remove an MX DNS record from your DNS records for a given (sub)domain
    $deleteMX = $cfClient->deleteDNSRecords('new.sub.example.com', 'MX');

    // Remove all DNS records for a given (sub)domain
    // This is not allowed on top level domains for security reasons
    // if you want to do it anyway, please connect to your Cloudflare console
    $deleteDomain = $cfClient->deleteDNSRecords('sub.example.com');
```

### Hacking the library

This is a free/libre library under license LGPL v3 or later. Your pull requests and/or feedback is very welcome!

### Contributors
Created by the ReolServices Edge Team, Renaud Tilte, Simith D'Oliveira, and contributors.

We are looking forward to your contributions and pull requests!

## Tests

Coming soon
