# Document Loader Tika

Provides a [Document Loader](https://www.drupal.org/project/document_loader) plugin that extracts text from files
with [Apache Tika](https://tika.apache.org/). Tika reads Word, PDF, presentations, spreadsheets, HTML, Markdown and
plain text, so one loader covers every file-based document type of the framework.

Choose one extraction source:

- **Tika server** sends the file to an Apache Tika REST API.
- **Local Tika executable** runs a `tika-app` JAR on the Drupal web server.

## Requirements

- Drupal 10.4 or 11
- Document Loader 2.0 or later
- One of the following:
  - a reachable Apache Tika server; or
  - Java on `PATH` and a readable `tika-app` command-line JAR on the web server.

## Installation

```bash
composer require drupal/document_loader_tika
drush en document_loader_tika
```

## Configuration

Configure the extraction mode and timeout at Administration > Configuration > Media > Document Loader > Apache Tika
(`/admin/config/media/document-loader/tika`). The default mode is **Tika server** with a 30-second timeout. The
server URL and the app JAR path are empty, so set the one the chosen mode needs.

### Tika server

Run a Tika server, for example with the official Docker image:

```bash
docker run -p 9998:9998 apache/tika:3.3.1.0
```

Select **Tika server** and enter its base URL. The module sends the document to the server's `/tika` endpoint and
uses `/version` to check availability.

### Local Tika executable

Select **Local Tika executable** and enter the absolute path to a `tika-app` JAR. This mode runs:

```text
java -Djava.awt.headless=true -Dfile.encoding=UTF-8 -jar /path/to/tika-app.jar --text /path/to/document
```

For HTML output, it uses `--xhtml` instead of `--text`. Java must be discoverable on `PATH`. The process runs with
the `C.UTF-8` locale so UTF-8 filenames are preserved, and does not pass the filename through a shell. Saving the
settings verifies that the JAR is readable and that Java can run `tika-app --version`.

For per-environment values, override the configuration in `settings.php`:

```php
$config['document_loader_tika.settings']['url'] = 'http://tika.internal:9998';
$config['document_loader_tika.settings']['timeout'] = 60;
```

For local executable mode:

```php
$config['document_loader_tika.settings']['mode'] = 'executable';
$config['document_loader_tika.settings']['jar_path'] = '/usr/local/lib/tika-app.jar';
$config['document_loader_tika.settings']['timeout'] = 60;
```

The status report checks the server and app versions with a fixed two-second timeout. The selected source is labelled
as active.

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

In server mode, an unreachable server, a non-200 response, or an empty body raises a `DocumentLoaderException` from
the manager. In executable mode, the same happens when Java or the JAR cannot run, the command times out, exits
unsuccessfully, or returns no text. The plugin reports itself unavailable when the selected source cannot return a
version.

## Logging

Every extraction is logged to the `document_loader_tika` channel, visible at Administration > Reports > Recent log
messages. One entry records the start, naming the mode and the file name. A second records the outcome: the
character count and the elapsed seconds on success, the reason on failure.

## Maintainers

- OpenEuropa team, European Commission
