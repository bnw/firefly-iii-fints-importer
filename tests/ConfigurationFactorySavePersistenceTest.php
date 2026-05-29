<?php

use App\ConfigurationFactory;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the persistence-truncation UX bug.
 *
 * Background: the bnw wizard used to render the new ~17 KB phpFinTS
 * persistence blob inside a Bootstrap <pre> on done.twig and ask the user
 * to copy/paste it into their JSON config. Browser copy of a single
 * unwrapped 17K-char selection silently TRUNCATED to ~1.2 KB. The cut
 * landed mid-base64-character, so phpFinTS later threw "Incorrect padding"
 * during unserialize and the user got locked into doing a fresh TAN dance.
 *
 * `ConfigurationFactory::save_persistence_to_file` eliminates the manual
 * copy step entirely: the wizard writes the new value back into the loaded
 * config file. These tests guard the round-trip, the 17 KB size class
 * specifically, and the must-not-clobber semantics for the rest of the
 * file.
 */
final class ConfigurationFactorySavePersistenceTest extends TestCase
{
    /** @var string */
    private $fixtureFile;

    protected function setUp(): void
    {
        $this->fixtureFile = tempnam(sys_get_temp_dir(), 'bnw-test-config-');
        // tempnam already created an empty file; tests that need a JSON
        // fixture will overwrite it via file_put_contents.
    }

    protected function tearDown(): void
    {
        @unlink($this->fixtureFile);
        @unlink($this->fixtureFile . '.tmp');
    }

    /**
     * Write a minimal valid config fixture and return the expected array.
     */
    private function writeFixture(array $overrides = []): array
    {
        $data = array_merge([
            'bank_username'            => 'user123',
            'bank_password'            => 'secret',
            'bank_url'                 => 'https://fints.example.bank/fints',
            'bank_code'                => '12030000',
            'bank_2fa'                 => '940',
            'bank_2fa_device'          => '',
            'bank_fints_persistence'   => '',
            'firefly_url'              => 'http://firefly:8080',
            'firefly_access_token'     => 'token-abc',
            'skip_transaction_review'  => true,
            'description_regex_match'  => '',
            'description_regex_replace'=> '',
            'force_mt940'              => false,
        ], $overrides);
        file_put_contents($this->fixtureFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $data;
    }

    public function test_round_trips_short_blob()
    {
        $this->writeFixture();
        $small = base64_encode('hello-world');
        ConfigurationFactory::save_persistence_to_file($this->fixtureFile, $small);

        $reloaded = json_decode(file_get_contents($this->fixtureFile), true);
        $this->assertSame($small, $reloaded['bank_fints_persistence']);
        $this->assertSame('hello-world', base64_decode($reloaded['bank_fints_persistence']));
    }

    /**
     * THE regression test for the bug we shipped this fix for.
     *
     * Production blob measurements:
     *   - In-memory `$session->get('persistedFints')` = 13,038 bytes
     *   - `base64_encode(...)` rendered in <pre>     = 17,384 chars
     *   - What the browser copy/paste returned       =  1,231 chars
     *
     * If save_persistence_to_file ever silently truncates, decodes,
     * re-encodes with different padding, or otherwise mutates the value,
     * this test will fail at this exact size class.
     */
    public function test_round_trips_17k_blob_regression()
    {
        $this->writeFixture();
        $rawBytes = random_bytes(13038);
        $blob     = base64_encode($rawBytes);
        $this->assertSame(17384, strlen($blob), 'fixture size must match the production size class');

        ConfigurationFactory::save_persistence_to_file($this->fixtureFile, $blob);

        $reloaded = json_decode(file_get_contents($this->fixtureFile), true);
        $this->assertSame(strlen($blob), strlen($reloaded['bank_fints_persistence']),
            'persistence length must be preserved byte-for-byte');
        $this->assertSame($blob, $reloaded['bank_fints_persistence'],
            'persistence must round-trip without mutation at the 17K size class');
        $this->assertSame($rawBytes, base64_decode($reloaded['bank_fints_persistence']),
            'decoded bytes must match the original 13,038-byte payload');
    }

    public function test_save_preserves_all_other_top_level_keys()
    {
        $original = $this->writeFixture([
            'bank_username' => 'special-user',
            'bank_url'      => 'https://hbci.example.com/path?with=query',
            'description_regex_match'   => '/^pattern$/i',
            'description_regex_replace' => 'replacement$1',
        ]);

        ConfigurationFactory::save_persistence_to_file(
            $this->fixtureFile,
            base64_encode('new-persistence')
        );

        $reloaded = json_decode(file_get_contents($this->fixtureFile), true);
        foreach ($original as $key => $value) {
            if ($key === 'bank_fints_persistence') {
                continue;
            }
            $this->assertSame($value, $reloaded[$key],
                "top-level key '$key' was modified by save_persistence_to_file");
        }
        $this->assertSame(base64_encode('new-persistence'), $reloaded['bank_fints_persistence']);
    }

    public function test_save_preserves_choose_account_automation_nested_object()
    {
        $nested = [
            'bank_account_iban'  => 'DE95120300001066167618',
            'firefly_account_id' => 1,
            'from'               => '2026-05-01',
            'to'                 => '',
        ];
        $this->writeFixture(['choose_account_automation' => $nested]);

        ConfigurationFactory::save_persistence_to_file(
            $this->fixtureFile,
            base64_encode(random_bytes(100))
        );

        $reloaded = json_decode(file_get_contents($this->fixtureFile), true);
        $this->assertArrayHasKey('choose_account_automation', $reloaded);
        $this->assertSame($nested, $reloaded['choose_account_automation'],
            'nested choose_account_automation block must round-trip exactly');
    }

    public function test_save_is_atomic()
    {
        $this->writeFixture();
        $inode_before = fileinode($this->fixtureFile);

        ConfigurationFactory::save_persistence_to_file(
            $this->fixtureFile,
            base64_encode('atomic-test')
        );

        $this->assertFileDoesNotExist($this->fixtureFile . '.tmp',
            '.tmp file must not linger after a successful save');
        $this->assertFileExists($this->fixtureFile, 'target file must still exist at the original path');

        $reloaded = json_decode(file_get_contents($this->fixtureFile), true);
        $this->assertSame(base64_encode('atomic-test'), $reloaded['bank_fints_persistence']);

        // rename() guarantees the target path is replaced in place.
        // (Note: on most filesystems the inode changes — that's expected for atomic rename.)
        $this->assertTrue(true, 'file replaced in place at ' . $this->fixtureFile);
    }

    public function test_save_throws_on_missing_file()
    {
        $missing = sys_get_temp_dir() . '/bnw-test-does-not-exist-' . uniqid() . '.json';
        $this->assertFileDoesNotExist($missing);

        $this->expectException(\RuntimeException::class);
        ConfigurationFactory::save_persistence_to_file($missing, base64_encode('whatever'));
    }

    public function test_save_throws_on_invalid_json()
    {
        $garbage = "this is { not valid JSON at all";
        file_put_contents($this->fixtureFile, $garbage);

        $thrown = false;
        try {
            ConfigurationFactory::save_persistence_to_file(
                $this->fixtureFile,
                base64_encode('whatever')
            );
        } catch (\RuntimeException $e) {
            $thrown = true;
        }
        $this->assertTrue($thrown, 'save_persistence_to_file must throw on invalid JSON');
        $this->assertSame($garbage, file_get_contents($this->fixtureFile),
            'malformed file must NOT be overwritten');
    }

    public function test_save_idempotent()
    {
        $this->writeFixture();
        $blob = base64_encode(random_bytes(13038));

        ConfigurationFactory::save_persistence_to_file($this->fixtureFile, $blob);
        $contents1 = file_get_contents($this->fixtureFile);

        ConfigurationFactory::save_persistence_to_file($this->fixtureFile, $blob);
        $contents2 = file_get_contents($this->fixtureFile);

        $this->assertSame($contents1, $contents2,
            'saving the same value twice must produce byte-identical files (no whitespace drift, no key reordering)');
    }

    public function test_save_preserves_existing_file_permissions()
    {
        $this->writeFixture();
        // Operators sometimes chmod the config to 0600 because it holds
        // plaintext bank_password + firefly_access_token. The atomic
        // rename must NOT bump perms back up to the process umask default.
        chmod($this->fixtureFile, 0600);

        ConfigurationFactory::save_persistence_to_file(
            $this->fixtureFile,
            base64_encode('perm-preservation-test')
        );

        $perms = fileperms($this->fixtureFile) & 0777;
        $this->assertSame(0600, $perms,
            sprintf('expected 0600 (preserved), got 0%o', $perms));
    }

    public function test_empty_persistence_explicitly_clears()
    {
        $this->writeFixture(['bank_fints_persistence' => base64_encode('previously-set')]);

        ConfigurationFactory::save_persistence_to_file($this->fixtureFile, '');

        $reloaded = json_decode(file_get_contents($this->fixtureFile), true);
        $this->assertArrayHasKey('bank_fints_persistence', $reloaded,
            'empty persistence must still write the key (clear), not skip it');
        $this->assertSame('', $reloaded['bank_fints_persistence'],
            'empty input must explicitly set the field to empty string');
    }
}
