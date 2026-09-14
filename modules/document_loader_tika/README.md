# Document Loader Tika

Provides a [Document Loader](https://www.drupal.org/project/document_loader) plugin that extracts text from files
through an [Apache Tika](https://tika.apache.org/) server. Tika reads Word, PDF, presentations, spreadsheets, HTML,
Markdown and plain text, so one loader covers every file-based document type of the framework.

Nothing runs in PHP: the file is sent to the Tika REST API and the response is returned as the loader output. No
system packages and no parsing libraries are needed on the web server.

## Requirements

- Drupal 10.4 or 11
- Document Loader 2.0 or later
- A reachable Apache Tika server. The official Docker image is enough: `docker run -p 9998:9998 apache/tika:3.3.1.0`

## Installation

```bash
composer require drupal/document_loader_tika
drush en document_loader_tika
```

## Configuration

Set the server URL and the request timeout at Administration > Configuration > Media > Document Loader >
Apache Tika server (`/admin/config/media/document-loader/tika`). The defaults are `http://tika:9998` and 30 seconds.

For per-environment values, override the configuration in `settings.php`:

```php
$config['document_loader_tika.settings']['url'] = 'http://tika.internal:9998';
$config['document_loader_tika.settings']['timeout'] = 60;
```

The status report shows whether the server answers and which version it runs.

## Usage

The plugin id is `document_loader_tika:tika`. It supports the `text` output (Tika plain text) and the `html` output
(Tika XHTML, which keeps headings, lists and tables). Load a file through the Document Loader manager as usual:

```php
use Drupal\document_loader\DocumentLoaderType\Input\PdfInput;

$result = \Drupal::service('document_loader.manager')->loadFromInput(
  'document_loader_type:pdf',
  new PdfInput('private://reports/report.pdf'),
  \Drupal::currentUser(),
  'text',
);
$text = $result->content;
```

The plugin is picked automatically for its document types unless another loader is configured as the default in the
Document Loader settings.

## Errors

A server that cannot be reached, a non-200 response or an empty body raise a `DocumentLoaderException` from the
manager. The plugin reports itself unavailable while the server does not answer its version endpoint.

## Maintainers

- OpenEuropa team, European Commission
