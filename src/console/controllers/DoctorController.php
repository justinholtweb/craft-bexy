<?php

namespace justinholtweb\bexy\console\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\console\Controller;
use craft\helpers\Console;
use justinholtweb\bexy\errors\BexioApiException;
use justinholtweb\bexy\models\Document;
use justinholtweb\bexy\models\Settings;
use justinholtweb\bexy\Plugin;
use yii\console\ExitCode;

/**
 * Everything that has to be true before the first order arrives, checked in one go.
 *
 * The alternative is finding out at checkout, when a real customer's order is the thing that
 * fails — and every gap this looks for is one that produces a document bexio accepts and an
 * accountant does not.
 */
class DoctorController extends Controller
{
    private int $problems = 0;
    private int $warnings = 0;

    public function actionIndex(): int
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $this->heading('Craft');
        $this->check('Commerce is installed and enabled', Plugin::commerceIsReady());

        $this->heading('Connection');
        $this->checkConnection($settings);

        $this->heading('bexio defaults');
        $this->checkDefaults($settings);

        $this->heading('Tax mapping');
        $this->checkTaxMapping($settings);

        $this->heading('Documents');
        $this->checkDocuments();

        $this->stdout("\n");

        if ($this->problems) {
            $this->stdout(sprintf("%d problem%s, %d warning%s.\n", $this->problems, $this->problems === 1 ? '' : 's', $this->warnings, $this->warnings === 1 ? '' : 's'), Console::FG_RED);

            return ExitCode::CONFIG;
        }

        $this->stdout(sprintf("No problems, %d warning%s.\n", $this->warnings, $this->warnings === 1 ? '' : 's'), $this->warnings ? Console::FG_YELLOW : Console::FG_GREEN);

        return ExitCode::OK;
    }

    private function checkConnection(Settings $settings): void
    {
        $auth = Plugin::getInstance()->getAuth();

        if ($settings->authMode === Settings::AUTH_PAT) {
            $this->check('A personal access token is set', $settings->getPersonalAccessToken() !== '');
            $this->warn(
                'Personal access tokens expire 60 days after bexio issues them',
                false,
                'Switch to the authorization code flow, or diarise replacing the token.',
            );
        } else {
            $this->check('A client ID and secret are set', $settings->getClientId() !== '' && $settings->getClientSecret() !== '');
            $this->check('Bexy is connected', $auth->isConnected(), 'Open Bexy’s settings and press Connect.');

            $tokenSet = $auth->getTokenSet();

            if ($tokenSet) {
                $this->check('A refresh token was issued', (bool)$tokenSet->refreshToken, 'Reconnect and make sure the offline_access scope is granted, or the connection will end with the session.');
            }
        }

        if (!$auth->isConnected()) {
            return;
        }

        $result = Plugin::getInstance()->getApi()->testConnection();
        $this->check('bexio answers', $result['ok'], $result['message']);
    }

    private function checkDefaults(Settings $settings): void
    {
        $contacts = Plugin::getInstance()->getContacts();

        $this->check(
            'A bexio user is available',
            (bool)$contacts->bexioUserId(),
            'bexio requires a user on every contact and document. Pick one in settings.',
        );

        $this->check(
            'A default revenue account is set',
            (bool)$settings->defaultAccountId,
            'Without one, positions land in bexio’s fallback account and the books need re-posting by hand.',
        );

        $this->check(
            'A default sales tax is set',
            (bool)$settings->defaultTaxId,
            'Without one, bexio applies the document default, which is rarely the rate the shop charged.',
        );

        $this->warn(
            'A unit is set for line quantities',
            (bool)$settings->defaultUnitId,
            'Positions will have no unit next to their quantity.',
        );

        if ($settings->sendDocument) {
            $this->check(
                'The invoice email contains [Network Link]',
                str_contains($settings->emailBody, '[Network Link]'),
                'bexio only attaches the document where that placeholder appears.',
            );

            $this->check(
                'Invoices are issued before being sent',
                $settings->issueDocument,
                'bexio will not email a draft. Turn on “Issue the invoice”.',
            );
        }

        if ($settings->documentType === Document::TYPE_ORDER && $settings->pushPayments) {
            $this->warn(
                'Payments are pushed',
                false,
                'Payments attach to invoices, not orders. With the document type set to Order, nothing will be posted.',
            );
        }
    }

    private function checkTaxMapping(Settings $settings): void
    {
        if (!Plugin::commerceIsReady()) {
            return;
        }

        $categories = Commerce::getInstance()->getTaxCategories()->getAllTaxCategories();
        $unmapped = [];

        foreach ($categories as $category) {
            $mapped = false;

            foreach ($settings->taxMap as $row) {
                if (($row['taxCategory'] ?? null) === $category->handle && !empty($row['taxId'])) {
                    $mapped = true;
                    break;
                }
            }

            if (!$mapped) {
                $unmapped[] = $category->handle;
            }
        }

        $this->warn(
            'Every Commerce tax category maps to a bexio tax',
            $unmapped === [],
            $unmapped ? 'Falling back to the default tax for: ' . implode(', ', $unmapped) : '',
        );

        $taxIds = array_column(Plugin::getInstance()->getMeta()->getTaxes(), 'id');

        if ($taxIds && $settings->defaultTaxId) {
            $this->check(
                'The default tax is an active sales tax in bexio',
                in_array($settings->defaultTaxId, array_map('intval', $taxIds), true),
                'Only active sales taxes can be used on a document; anything else is rejected at push time.',
            );
        }
    }

    private function checkDocuments(): void
    {
        $documents = Plugin::getInstance()->getDocuments();
        $counts = $documents->getCounts();

        $this->stdout(sprintf(
            "  %d total · %d pending · %d synced · %d failed · %d need attention\n",
            $counts['total'],
            $counts['pending'],
            $counts['synced'],
            $counts['failed'],
            $counts['attention'],
        ));

        $this->warn('No failed documents', $counts['failed'] === 0, 'Run craft bexy/sync/pending to retry them.');
        $this->warn('No documents need attention', $counts['attention'] === 0, 'See the Documents screen; these usually mean a refund bexio cannot credit automatically.');

        $mismatched = 0;

        foreach ($documents->getDocuments([], 500) as $document) {
            if ($document->getHasRoundingDelta()) {
                $mismatched++;
            }
        }

        $this->warn(
            'No totals mismatches',
            $mismatched === 0,
            $mismatched ? sprintf('%d document(s) needed a rounding position. Check the tax mapping.', $mismatched) : '',
        );
    }

    private function heading(string $text): void
    {
        $this->stdout("\n" . $text . "\n", Console::BOLD);
    }

    private function check(string $label, bool $ok, string $fix = ''): void
    {
        if ($ok) {
            $this->stdout('  ✓ ' . $label . "\n", Console::FG_GREEN);

            return;
        }

        $this->problems++;
        $this->stdout('  ✗ ' . $label . "\n", Console::FG_RED);

        if ($fix !== '') {
            $this->stdout('      ' . $fix . "\n", Console::FG_GREY);
        }
    }

    private function warn(string $label, bool $ok, string $fix = ''): void
    {
        if ($ok) {
            $this->stdout('  ✓ ' . $label . "\n", Console::FG_GREEN);

            return;
        }

        $this->warnings++;
        $this->stdout('  ! ' . $label . "\n", Console::FG_YELLOW);

        if ($fix !== '') {
            $this->stdout('      ' . $fix . "\n", Console::FG_GREY);
        }
    }
}
