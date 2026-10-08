<?php

declare(strict_types=1);

namespace Drupal\Tests\document_loader_tika\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\document_loader_tika\Form\TikaSettingsForm;
use Drupal\document_loader_tika\TikaClientInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests executable-mode Tika extraction.
 */
#[Group('document_loader_tika')]
class TikaExecutableClientTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'document_loader_tika'];

  /**
   * Directory holding the fake Java executable and test files.
   */
  private string $directory;

  /**
   * The environment PATH before the fake Java executable was added.
   */
  private string $originalPath;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['document_loader_tika']);
    $this->directory = sys_get_temp_dir() . '/tika-executable-' . bin2hex(random_bytes(8));
    mkdir($this->directory);
    $this->originalPath = (string) getenv('PATH');
    putenv('PATH=' . $this->directory . ':' . $this->originalPath);
    $this->writeFakeJava();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('PATH=' . $this->originalPath);
    array_map('unlink', glob($this->directory . '/*') ?: []);
    rmdir($this->directory);
    parent::tearDown();
  }

  /**
   * Tests executable extraction uses safe arguments and a UTF-8 environment.
   */
  public function testExtractionUsesExpectedCommandAndEnvironment(): void {
    $jar = $this->configureExecutableMode();
    $source = $this->directory . '/brief-é.txt';
    file_put_contents($source, 'Source text');

    $this->assertSame('Extracted text', $this->client()->extract($source));

    $lines = file($this->capturePath(), FILE_IGNORE_NEW_LINES);
    $this->assertContains('LANG=C.UTF-8', $lines);
    $this->assertContains('LC_ALL=C.UTF-8', $lines);
    $this->assertContains('ARG=-Djava.awt.headless=true', $lines);
    $this->assertContains('ARG=-Dfile.encoding=UTF-8', $lines);
    $this->assertContains('ARG=-jar', $lines);
    $this->assertContains('ARG=' . $jar, $lines);
    $this->assertContains('ARG=--text', $lines);
    $this->assertContains('ARG=' . $source, $lines);
  }

  /**
   * Tests HTML extraction requests Tika XHTML output.
   */
  public function testHtmlExtractionUsesXhtmlCommand(): void {
    $this->configureExecutableMode();
    $source = $this->directory . '/brief.html';
    file_put_contents($source, '<p>Source text</p>');

    $this->assertSame('Extracted text', $this->client()->extract($source, 'text/html'));

    $lines = file($this->capturePath(), FILE_IGNORE_NEW_LINES);
    $this->assertContains('ARG=--xhtml', $lines);
    $this->assertContains('ARG=' . $source, $lines);
  }

  /**
   * Tests executable availability probes the JAR version.
   */
  public function testAvailabilityReadsJarVersion(): void {
    $this->configureExecutableMode();

    $this->assertSame('Apache Tika 3.0.0', $this->client()->version());
    $this->assertTrue($this->client()->isAvailable());

    $lines = file($this->capturePath(), FILE_IGNORE_NEW_LINES);
    $this->assertContains('ARG=--version', $lines);
  }

  /**
   * Tests the settings form validates the executable JAR and Java command.
   */
  public function testSettingsFormValidatesExecutable(): void {
    $form = $this->settingsForm();
    $invalidState = new FormState();
    $builtForm = $form->buildForm([], $invalidState);
    $invalidState->setValues([
      'mode' => 'executable',
      'jar_path' => $this->directory . '/missing.jar',
      'timeout' => 30,
    ]);
    $form->validateForm($builtForm, $invalidState);
    $this->assertArrayHasKey('jar_path', $invalidState->getErrors());

    $jar = $this->directory . '/tika-app.jar';
    file_put_contents($jar, 'Not read by the fake Java executable.');
    $validState = new FormState();
    $builtForm = $form->buildForm([], $validState);
    $validState->setValues([
      'mode' => 'executable',
      'jar_path' => $jar,
      'timeout' => 30,
    ]);
    $form->validateForm($builtForm, $validState);

    $this->assertSame([], $validState->getErrors());
    $this->assertContains('ARG=--version', file($this->capturePath(), FILE_IGNORE_NEW_LINES));
  }

  /**
   * Configures a readable JAR path and selects executable mode.
   */
  private function configureExecutableMode(): string {
    $jar = $this->directory . '/tika-app.jar';
    file_put_contents($jar, 'Not read by the fake Java executable.');
    $this->config('document_loader_tika.settings')
      ->set('mode', 'executable')
      ->set('jar_path', $jar)
      ->save();

    return $jar;
  }

  /**
   * Gets the mode-selecting client.
   */
  private function client(): TikaClientInterface {
    return $this->container->get(TikaClientInterface::class);
  }

  /**
   * Creates the executable-mode settings form.
   */
  private function settingsForm(): TikaSettingsForm {
    $form = TikaSettingsForm::create($this->container);
    $form->setStringTranslation($this->container->get('string_translation'));

    return $form;
  }

  /**
   * Gets the fake executable's command capture path.
   */
  private function capturePath(): string {
    return $this->directory . '/capture';
  }

  /**
   * Creates an executable which records its arguments and environment.
   */
  private function writeFakeJava(): void {
    $script = '#!/bin/sh' . PHP_EOL
      . 'capture="' . $this->capturePath() . '"' . PHP_EOL
      . '{' . PHP_EOL
      . '  printf "LANG=%s\\n" "$LANG"' . PHP_EOL
      . '  printf "LC_ALL=%s\\n" "$LC_ALL"' . PHP_EOL
      . '  for argument in "$@"; do printf "ARG=%s\\n" "$argument"; done' . PHP_EOL
      . '} > "$capture"' . PHP_EOL
      . 'case " $* " in' . PHP_EOL
      . '  *" --version "*) printf "Apache Tika 3.0.0\\n" ;;' . PHP_EOL
      . '  *) printf "Extracted text\\n" ;;' . PHP_EOL
      . 'esac' . PHP_EOL;
    $path = $this->directory . '/java';
    file_put_contents($path, $script);
    chmod($path, 0755);
  }

}
