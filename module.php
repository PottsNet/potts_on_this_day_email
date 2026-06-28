<?php

declare(strict_types=1);

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Fact;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleBlockInterface;
use Fisharebest\Webtrees\Module\ModuleBlockTrait;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Session;
use Fisharebest\Webtrees\Services\CalendarService;
use Fisharebest\Webtrees\Services\EmailService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Illuminate\Support\Collection;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

return new class extends AbstractModule implements ModuleCustomInterface, ModuleBlockInterface, ModuleConfigInterface {
    use ModuleBlockTrait;
    use ModuleConfigTrait;

    private const LIMIT_LOW = 10;
    private const LIMIT_HIGH = 20;
    private const DEFAULT_TIMEZONE = 'UTC';

    /** @var array<string, array<int, array<int, array<string, string>>>> */
    private array $historical_context_cache = [];

    /** @var array<string, int|null> */
    private array $relationship_distance_cache = [];

    /** @var array<string, array<int, string>|null> */
    private array $relationship_path_cache = [];

    public function title(): string
    {
        return 'Potts On This Day Email';
    }

    public function description(): string
    {
        return 'Send personalised On This Day emails to registered users who opt in, using webtrees email delivery and each subscriber’s privacy permissions.';
    }

    public function customModuleAuthorName(): string
    {
        return 'Jason Potts';
    }

    public function customModuleVersion(): string
    {
        return '1.0.0-beta.5';
    }

    public function customModuleLatestVersion(): string
    {
        return $this->customModuleVersion();
    }

    public function customModuleLatestVersionUrl(): string
    {
        return '';
    }

    public function customModuleSupportUrl(): string
    {
        return 'https://github.com/PottsNet/potts_on_this_day_email/issues';
    }

    public function customTranslations(string $language): array
    {
        return [];
    }

    public function getAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertAdministrator($request);
        $this->layout = 'layouts/administration';
        View::registerNamespace('potts-on-this-day-email', $this->resourcesFolder() . 'views/');

        $trees = $this->availableTrees();
        $requested_tree = $this->requestedTreeName($request);
        $settings = $this->settings();
        $selected_tree = $this->treeFromName($requested_tree)
            ?? $this->treeFromName((string) ($settings['tree'] ?? ''))
            ?? ($trees[0] ?? null);
        $return_url = $this->cleanReturnUrl(Validator::queryParams($request)->string('return_url', ''));
        $tree_urls = [];
        foreach ($trees as $tree) {
            $tree_urls[$tree->name()] = $this->moduleAdminUrl($tree, [], $return_url);
        }

        return $this->viewResponse('potts-on-this-day-email::admin/settings', [
            'title'          => I18N::translate('Potts On This Day Email settings'),
            'action_url'     => $this->moduleAdminUrl($selected_tree, [], $return_url),
            'control_panel_url' => $this->controlPanelUrl(),
            'return_url'     => $return_url,
            'module_name'    => $this->name(),
            'trees'          => $trees,
            'tree_urls'      => $tree_urls,
            'selected_tree'  => $selected_tree,
            'settings'       => $settings,
            'saved'          => Validator::queryParams($request)->boolean('saved', false),
            'prepared'       => Validator::queryParams($request)->boolean('prepared', false),
            'token_reset'    => Validator::queryParams($request)->boolean('token_reset', false),
            'error'          => Validator::queryParams($request)->string('error', ''),
            'version'        => $this->customModuleVersion(),
            'subscriber_html'=> $selected_tree instanceof Tree ? $this->subscriberDetailsHtml($selected_tree) : '',
            'scheduler_html' => $selected_tree instanceof Tree ? $this->schedulerDetailsHtml($selected_tree, $settings) : '',
        ]);
    }

    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->assertAdministrator($request);

        $parsed = $request->getParsedBody();
        $data = is_array($parsed) ? $parsed : [];
        $task = isset($data['task']) && is_string($data['task']) ? $data['task'] : 'save';
        $tree_name = isset($data['tree']) && is_string($data['tree']) ? trim($data['tree']) : '';
        $return_url = isset($data['return_url']) && is_string($data['return_url'])
            ? $this->cleanReturnUrl($data['return_url'])
            : '';
        $tree = $this->treeFromName($tree_name);

        if (!$tree instanceof Tree) {
            return $this->adminRedirect('', ['error' => 'Choose a valid family tree.'], $return_url);
        }

        $settings = $this->settings();
        $settings['tree'] = $tree->name();

        if ($task === 'prepare' || $task === 'reset_token') {
            $settings['token'] = bin2hex(random_bytes(24));

            if (!$this->saveSettings($settings)) {
                return $this->adminRedirect($tree->name(), ['error' => 'The scheduler settings could not be saved. Check that the module data directory is writable.'], $return_url);
            }

            return $this->adminRedirect($tree->name(), [$task === 'reset_token' ? 'token_reset' : 'prepared' => '1'], $return_url);
        }

        $timezone = isset($data['timezone']) && is_string($data['timezone'])
            ? trim($data['timezone'])
            : self::DEFAULT_TIMEZONE;
        $sender_email = isset($data['sender_email']) && is_string($data['sender_email'])
            ? trim($data['sender_email'])
            : '';
        $sender_name = isset($data['sender_name']) && is_string($data['sender_name'])
            ? $this->plain($data['sender_name'])
            : '';

        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            return $this->adminRedirect($tree->name(), ['error' => 'Enter a valid PHP timezone, such as Australia/Melbourne or Europe/London.'], $return_url);
        }

        if (filter_var($sender_email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->adminRedirect($tree->name(), ['error' => 'Enter a valid sender email address.'], $return_url);
        }

        $settings['timezone'] = $timezone;
        $settings['sender_email'] = $sender_email;
        $settings['sender_name'] = $sender_name !== '' ? $sender_name : $sender_email;

        if (!$this->saveSettings($settings)) {
            return $this->adminRedirect($tree->name(), ['error' => 'The settings could not be saved. Check that the module data directory is writable.'], $return_url);
        }

        return $this->adminRedirect($tree->name(), ['saved' => '1'], $return_url);
    }

    public function getBlock(Tree $tree, int $block_id, string $context, array $config = []): string
    {
        $alert = '';
        $settings = $this->settings();
        $user = Auth::user();
        $is_signed_in = $user->id() > 0;

        if ($is_signed_in) {
            $user_relationship_alert = $this->handleUserRelationshipSettingsRequest($tree, $block_id, $user);
            if ($user_relationship_alert !== '') {
                $alert .= $user_relationship_alert;
            }
        }

        $personal_relationship_settings = $is_signed_in
            ? $this->effectiveUserRelationshipSettings($tree, $user)
            : $settings;
        $personal_root_xref = $is_signed_in ? $this->relationshipRootXref($personal_relationship_settings, '') : '';
        $personal_ready = $is_signed_in && $personal_root_xref !== '';

        $facts = $personal_ready
            ? $this->todayFacts($tree, $personal_relationship_settings)
            : new Collection();

        $send_requested = (string) ($_GET['potts_otd_send'] ?? '');
        if ($send_requested !== '' && (int) ($_GET['potts_otd_block_id'] ?? 0) === $block_id) {
            if ($personal_ready) {
                $alert .= $this->handleManualSend($tree, 'me', $facts, $personal_relationship_settings);
            } else {
                $alert .= '<p class="alert alert-warning mb-3">Choose your individual ID and save your settings before sending yourself a test email.</p>';
            }
        }

        if (!$is_signed_in) {
            $content = '<p class="text-muted mb-0">Sign in to choose your daily email settings.</p>';
        } elseif (!$personal_ready) {
            $content = '<p class="text-muted mb-0">Choose your individual ID and save your settings to see today\'s matching family events.</p>';
        } elseif ($facts->isEmpty()) {
            $content = '<p class="text-muted mb-0">No births, deaths or marriages were found for today after applying your current relationship filter.</p>';
        } else {
            $content = view('lists/anniversaries-table', [
                'facts'      => $facts,
                'limit_low'  => self::LIMIT_LOW,
                'limit_high' => self::LIMIT_HIGH,
                'order'      => [[2, 'desc']],
            ]);
        }

        $buttons = '';
        $personal_html = '';

        if ($is_signed_in) {
            if (Auth::isAdmin($user)) {
                $buttons .= '<p class="mb-2"><a class="btn btn-outline-secondary" href="' . $this->esc($this->moduleAdminUrl($tree, [], $this->currentPageUrl([]))) . '">On This Day Email settings</a></p>';
            }
            if ($personal_ready) {
                $buttons .= '<p class="mb-2"><a class="btn btn-primary" href="' . $this->esc($this->sendUrl($block_id, 'me')) . '">Send test email to my webtrees account</a></p>';
            }
            $personal_html = $this->userRelationshipSettingsHtml($tree, $user, $personal_relationship_settings, $block_id);
        } else {
            $personal_html = '<p class="alert alert-info mb-3">Sign in to choose whether you want a daily email and to set your relationship distance.</p>';
        }

        return '<div class="card wt-block potts-on-this-day-email-preview mb-3">'
            . '<div class="card-header"><h2 class="card-title h4 mb-0">On This Day daily email</h2></div>'
            . '<div class="card-body">'
            . '<div class="alert alert-info small mb-3"><strong>What this block does:</strong> It lets you choose whether you want a daily On This Day email. The email includes births, deaths and marriages from the family tree that happened on today\'s date, filtered around the person and relationship distance you choose below. You will only receive an email on days where there is something to report.</div>'
            . $alert
            . $buttons
            . $personal_html
            . $content
            . '</div>'
            . '</div>';
    }

    /**
     * Secure URL used by an external scheduled task.
     */
    public function getRunDailyAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->schedulerLog('RunDaily request received.');

        $tree = $request->getAttribute('tree');
        if (!$tree instanceof Tree) {
            $this->schedulerLog('ERROR - No tree was supplied in the URL.');
            return $this->textResponse('ERROR - No tree was supplied in the URL.', 400);
        }

        $query = $request->getQueryParams();
        $token = (string) ($query['token'] ?? '');
        $force = (string) ($query['force'] ?? '') === '1';
        $settings = $this->settings();
        $settings['last_scheduler_attempt'] = $this->localDateTime();
        $this->saveSettings($settings);

        if (($settings['token'] ?? '') === '' || !hash_equals((string) $settings['token'], $token)) {
            $settings['last_result'] = 'invalid token';
            $settings['last_error'] = 'Invalid or missing token on daily email request';
            $this->saveSettings($settings);
            $this->schedulerLog('ERROR - Invalid or missing token.');
            return $this->textResponse('ERROR - Invalid or missing token.', 403);
        }

        $opted_in_users = $this->dailyEmailOptIns($tree);
        if ($opted_in_users === []) {
            $settings['last_result'] = 'no subscribers';
            $settings['last_error'] = '';
            $this->saveSettings($settings);
            $this->schedulerLog('OK - No registered users have opted in.');
            return $this->textResponse('OK - No registered users have opted in to daily email.');
        }

        $today_key = $this->localDateKey();
        if (!$force && (string) ($settings['last_run'] ?? '') === $today_key) {
            $settings['last_result'] = 'already sent today';
            $settings['last_error'] = '';
            $this->saveSettings($settings);
            $this->schedulerLog('OK - Already sent today.');
            return $this->textResponse('OK - Already sent today. Add &force=1 to the URL to send it again for testing.');
        }

        $lock = $this->acquireRunLock();
        if ($lock === null) {
            $this->schedulerLog('OK - Another daily run is already in progress.');
            return $this->textResponse('OK - Another daily run is already in progress.', 409);
        }

        try {
            return $this->runDailyForSubscribers($tree, $settings, $opted_in_users);
        } finally {
            $this->releaseRunLock($lock);
        }
    }

    /**
     * @param array<int, array{user_id:int,user:UserInterface,recipient:array{email:string,name:string},settings:array<string,string>}> $opted_in_users
     */
    private function runDailyForSubscribers(Tree $tree, array $settings, array $opted_in_users): ResponseInterface
    {
        $today_key = $this->localDateKey();
        $subject = 'On this day in the family tree - ' . $this->localDateHeading();
        $sender = $this->senderFromSettings($settings, $opted_in_users[0]['recipient'] ?? ['email' => '', 'name' => '']);
        $sent = 0;
        $failed = [];
        $event_count_total = 0;
        $skipped_no_events = 0;

        foreach ($opted_in_users as $opted_in) {
            $user_settings = $opted_in['settings'];
            $subscriber_user = $opted_in['user'];

            $result_for_user = $this->runAsUser($subscriber_user, function () use ($tree, $user_settings, $sender, $subject, $opted_in): array {
                $user_facts = $this->todayFacts($tree, $user_settings);
                $event_count = $user_facts->count();

                $this->schedulerLog('Subscriber checked: user_id=' . (string) $opted_in['user_id'] . ', events found=' . $event_count . '.');

                if ($user_facts->isEmpty()) {
                    return ['sent' => 0, 'failed' => [], 'event_count' => 0, 'skipped_no_events' => 1];
                }

                $user_result = $this->sendToRecipients(
                    $sender,
                    [$opted_in['recipient']],
                    $subject,
                    $this->emailText($tree, $user_facts, $user_settings, 'Relationship to you'),
                    $this->emailHtml($tree, $user_facts, $user_settings, 'Relationship to you')
                );

                return [
                    'sent' => $user_result['sent'],
                    'failed' => $user_result['failed'],
                    'event_count' => $event_count,
                    'skipped_no_events' => 0,
                ];
            });

            $event_count_total += (int) $result_for_user['event_count'];
            $skipped_no_events += (int) $result_for_user['skipped_no_events'];
            $sent += (int) $result_for_user['sent'];
            $failed = array_merge($failed, $result_for_user['failed']);
        }

        if ($sent === 0 && $event_count_total === 0) {
            $settings['last_run'] = $today_key;
            $settings['last_result'] = 'no events';
            $settings['last_count'] = '0';
            $settings['last_recipients'] = '0';
            $settings['last_error'] = '';
            $this->saveSettings($settings);
            $message = 'OK - No matching On This Day events found. No emails sent. Skipped subscribers: ' . $skipped_no_events . '.';
            $this->schedulerLog($message);
            return $this->textResponse($message);
        }

        if ($sent === 0) {
            $settings['last_result'] = 'failed';
            $settings['last_count'] = (string) $event_count_total;
            $settings['last_recipients'] = '0';
            $settings['last_error'] = implode('; ', $failed);
            $this->saveSettings($settings);
            $this->schedulerLog('ERROR - No emails were sent. Check the webtrees email log.');
            return $this->textResponse('ERROR - No emails were sent. Check the webtrees email settings and error log. Failed: ' . implode('; ', $failed), 500);
        }

        $settings['last_run'] = $today_key;
        $settings['last_result'] = 'sent';
        $settings['last_count'] = (string) $event_count_total;
        $settings['last_recipients'] = (string) $sent;
        $settings['last_error'] = implode('; ', $failed);
        $this->saveSettings($settings);

        $message = 'OK - Email sent to ' . $sent . ' recipient(s) - ' . $event_count_total . ' event item(s) found across all personalised emails. Subscribers checked: ' . count($opted_in_users) . '. Skipped no-event subscribers: ' . $skipped_no_events . '.';
        if ($failed !== []) {
            $message .= ' Failed: ' . implode('; ', $failed);
        }

        $this->schedulerLog($message);
        return $this->textResponse($message);
    }

    public function loadAjax(): bool
    {
        return false;
    }

    public function isUserBlock(): bool
    {
        return true;
    }

    public function isTreeBlock(): bool
    {
        return true;
    }

    private function todayFacts(Tree $tree, ?array $relationship_settings = null): Collection
    {
        $calendar_service = new CalendarService();

        // webtrees/PHP may use a different server timezone. Calculate the
        // genealogy day using the timezone selected by the site manager.
        $old_timezone = date_default_timezone_get();
        date_default_timezone_set($this->localTimezoneName());

        try {
            $today = Registry::timestampFactory()->now();
            $julian_day = $today->julianDay();
        } finally {
            date_default_timezone_set($old_timezone);
        }

        $facts = $calendar_service->getEventsList(
            $julian_day,
            $julian_day,
            'BIRT|DEAT|MARR',
            false,
            'date_desc',
            $tree
        );

        return $this->filterFactsByRelationship($tree, $facts, $relationship_settings);
    }

    private function handleRelationshipSettingsRequest(Tree $tree, int $block_id, array $settings): string
    {
        $action = (string) ($_GET['potts_otd_relationship_action'] ?? '');
        if ($action !== 'save') {
            return '';
        }

        if ((int) ($_GET['potts_otd_block_id'] ?? 0) !== $block_id) {
            return '';
        }

        if (!Auth::isManager($tree)) {
            return '<p class="alert alert-danger mb-3">Only a tree manager or administrator can change the relationship filter.</p>';
        }

        $root_xref = strtoupper(trim((string) ($_GET['potts_otd_relationship_root_xref'] ?? '')));
        $max_steps = (int) ($_GET['potts_otd_relationship_max_steps'] ?? 4);
        $max_steps = max(0, min(20, $max_steps));
        $enabled = (string) ($_GET['potts_otd_relationship_enabled'] ?? '') === '1';

        $settings['relationship_filter_enabled'] = $enabled ? '1' : '0';
        $settings['relationship_root_xref'] = $root_xref;
        $settings['relationship_max_steps'] = (string) $max_steps;

        if (!$this->saveSettings($settings)) {
            return '<p class="alert alert-danger mb-3">Could not save the relationship filter settings. Check that <code>modules_v4/potts_on_this_day_email/data/</code> is writable.</p>';
        }

        return '<p class="alert alert-success mb-3">Relationship filter updated.</p>';
    }

    private function handleUserRelationshipSettingsRequest(Tree $tree, int $block_id, UserInterface $user): string
    {
        $action = (string) ($_GET['potts_otd_user_relationship_action'] ?? '');
        if ($action !== 'save') {
            return '';
        }

        if ((int) ($_GET['potts_otd_block_id'] ?? 0) !== $block_id) {
            return '';
        }

        if ($user->id() <= 0) {
            return '<p class="alert alert-danger mb-3">You need to be signed in to save your relationship settings.</p>';
        }

        $daily_email_enabled = (string) ($_GET['potts_otd_user_daily_email_enabled'] ?? '') === '1';
        $root_xref = strtoupper(trim((string) ($_GET['potts_otd_user_relationship_root_xref'] ?? '')));
        $max_steps = (int) ($_GET['potts_otd_user_relationship_max_steps'] ?? 4);
        $max_steps = max(0, min(20, $max_steps));
        $enabled = (string) ($_GET['potts_otd_user_relationship_enabled'] ?? '') === '1';

        $root_candidate = $root_xref !== '' ? Registry::individualFactory()->make($root_xref, $tree) : null;
        if ($root_xref !== '' && !$root_candidate instanceof Individual) {
            return '<p class="alert alert-danger mb-3">The root individual ID <code>' . $this->esc($root_xref) . '</code> was not found in this tree.</p>';
        }

        $recipient_email = $this->plain($user->email());
        if ($daily_email_enabled && filter_var($recipient_email, FILTER_VALIDATE_EMAIL) === false) {
            return '<p class="alert alert-danger mb-3">Your webtrees account does not have a valid email address, so daily email cannot be turned on.</p>';
        }

        if ($daily_email_enabled && $root_xref === '') {
            return '<p class="alert alert-danger mb-3">Choose your individual ID before turning on the daily email.</p>';
        }

        $settings = [
            'personal_preview_enabled' => '1',
            'daily_email_enabled' => $daily_email_enabled ? '1' : '0',
            'relationship_filter_enabled' => $enabled ? '1' : '0',
            'relationship_root_xref' => $root_xref,
            'relationship_max_steps' => (string) $max_steps,
            'email' => $recipient_email,
            'name' => $this->plain($user->realName()) ?: $this->plain($user->userName()),
        ];

        if (!$this->saveUserRelationshipSettings($tree, $user, $settings)) {
            return '<p class="alert alert-danger mb-3">Could not save your relationship filter settings. Check that <code>modules_v4/potts_on_this_day_email/data/</code> is writable.</p>';
        }

        return '<p class="alert alert-success mb-3">Your personal On This Day settings have been updated.</p>';
    }

    private function userRelationshipSettingsHtml(Tree $tree, UserInterface $user, array $settings, int $block_id): string
    {
        $daily_email_enabled = $this->dailyEmailEnabled($settings);
        $enabled = $this->relationshipFilterEnabled($settings);
        $root_xref = $this->relationshipRootXref($settings, '');
        $max_steps = $this->relationshipMaxSteps($settings);
        $linked_xref = $this->linkedIndividualXref($tree, $user);
        $root = $root_xref !== '' ? Registry::individualFactory()->make($root_xref, $tree) : null;
        $root_label = $root instanceof Individual
            ? $this->plain($root->fullName()) . ' (' . $root->xref() . ')'
            : ($root_xref !== '' ? 'Record not found: ' . $root_xref : 'No root person selected yet');

        $linked_text = $linked_xref !== ''
            ? 'Your linked webtrees individual appears to be <code>' . $this->esc($linked_xref) . '</code>.'
            : 'Your webtrees account does not appear to be linked to an individual record, so enter your individual ID manually.';

        $daily_email_checked = $daily_email_enabled ? ' checked' : '';
        $relationship_checked = $enabled ? ' checked' : '';
        $use_linked_button = '';
        if ($linked_xref !== '' && $linked_xref !== $root_xref) {
            $use_linked_url = $this->currentPageUrl([
                'potts_otd_user_relationship_action' => 'save',
                'potts_otd_block_id' => (string) $block_id,
                'potts_otd_user_daily_email_enabled' => $daily_email_enabled ? '1' : '0',
                'potts_otd_user_relationship_enabled' => '1',
                'potts_otd_user_relationship_root_xref' => $linked_xref,
                'potts_otd_user_relationship_max_steps' => (string) $max_steps,
            ]);
            $use_linked_button = '<p class="mb-2"><a class="btn btn-sm btn-outline-secondary" href="' . $this->esc($use_linked_url) . '">Use my linked individual as root</a></p>';
        }

        $email_badge = $daily_email_enabled
            ? '<span class="badge bg-success">Daily email on</span>'
            : '<span class="badge bg-secondary">Daily email off</span>';

        return '<div class="card mb-3">'
            . '<div class="card-header"><strong>My On This Day daily email</strong> ' . $email_badge . '</div>'
            . '<div class="card-body small">'
            . '<p class="mb-2"><strong>What happens if I turn on daily email?</strong> You will receive your own personalised On This Day summary at your webtrees account email address. It is only sent on days where there are matching family events for your settings. You can turn it off here at any time.</p>'
            . '<p class="mb-2"><strong>Current root:</strong> ' . $this->esc($root_label) . '</p>'
            . '<p class="text-muted mb-2">' . $linked_text . '</p>'
            . $use_linked_button
            . '<form method="get" action="' . $this->esc($this->currentPagePath()) . '" class="border rounded p-3 bg-light">'
            . $this->currentQueryHiddenFields(['potts_otd_user_relationship_action' => 'save', 'potts_otd_block_id' => (string) $block_id])
            . '<div class="row g-2 align-items-end">'
            . '<div class="col-md-3"><div class="form-check mb-2"><input class="form-check-input" id="potts_otd_user_daily_email_enabled" name="potts_otd_user_daily_email_enabled" type="checkbox" value="1"' . $daily_email_checked . '><label class="form-check-label" for="potts_otd_user_daily_email_enabled">Email me daily</label></div></div>'
            . '<div class="col-md-3"><div class="form-check mb-2"><input class="form-check-input" id="potts_otd_user_relationship_enabled" name="potts_otd_user_relationship_enabled" type="checkbox" value="1"' . $relationship_checked . '><label class="form-check-label" for="potts_otd_user_relationship_enabled">Use relationship filter</label></div></div>'
            . '<div class="col-md-3"><label class="form-label" for="potts_otd_user_relationship_root_xref">My individual ID</label><input class="form-control" id="potts_otd_user_relationship_root_xref" name="potts_otd_user_relationship_root_xref" type="text" value="' . $this->esc($root_xref) . '" placeholder="X123"></div>'
            . '<div class="col-md-3"><label class="form-label" for="potts_otd_user_relationship_max_steps">Maximum steps</label><input class="form-control" id="potts_otd_user_relationship_max_steps" name="potts_otd_user_relationship_max_steps" type="number" min="0" max="20" value="' . $this->esc((string) $max_steps) . '"></div>'
            . '<div class="col-md-3"><button type="submit" class="btn btn-success w-100">Save</button></div>'
            . '</div>'
            . '</form>'
            . '<p class="text-muted mt-2 mb-0">These settings are personal to your webtrees login. You will only receive emails if you tick Email me daily and there are matching family events. The site manager can still maintain a separate list for family update emails.</p>'
            . '</div>'
            . '</div>';
    }

    private function relationshipSettingsHtml(Tree $tree, array $settings, int $block_id): string
    {
        $enabled = $this->relationshipFilterEnabled($settings);
        $root_xref = $this->relationshipRootXref($settings);
        $max_steps = $this->relationshipMaxSteps($settings);
        $root = Registry::individualFactory()->make($root_xref, $tree);
        $root_label = $root instanceof Individual
            ? $this->plain($root->fullName()) . ' (' . $root->xref() . ')'
            : 'Record not found: ' . $root_xref;

        $checked = $enabled ? ' checked' : '';

        return '<div class="card mb-3">'
            . '<div class="card-header"><strong>Manual recipient relationship filter</strong></div>'
            . '<div class="card-body small">'
            . '<p class="mb-2">This only applies to the optional manual email recipient list. Registered users who tick Email me daily use their own personal settings instead. For marriage events, the marriage is included when either spouse is within the limit.</p>'
            . '<p class="mb-2"><strong>Current root:</strong> ' . $this->esc($root_label) . '</p>'
            . '<form method="get" action="' . $this->esc($this->currentPagePath()) . '" class="border rounded p-3 bg-light">'
            . $this->currentQueryHiddenFields(['potts_otd_relationship_action' => 'save', 'potts_otd_block_id' => (string) $block_id])
            . '<div class="row g-2 align-items-end">'
            . '<div class="col-md-3"><div class="form-check mb-2"><input class="form-check-input" id="potts_otd_relationship_enabled" name="potts_otd_relationship_enabled" type="checkbox" value="1"' . $checked . '><label class="form-check-label" for="potts_otd_relationship_enabled">Enable filter</label></div></div>'
            . '<div class="col-md-3"><label class="form-label" for="potts_otd_relationship_root_xref">Root individual ID</label><input class="form-control" id="potts_otd_relationship_root_xref" name="potts_otd_relationship_root_xref" type="text" value="' . $this->esc($root_xref) . '" placeholder="X123"></div>'
            . '<div class="col-md-3"><label class="form-label" for="potts_otd_relationship_max_steps">Maximum steps</label><input class="form-control" id="potts_otd_relationship_max_steps" name="potts_otd_relationship_max_steps" type="number" min="0" max="20" value="' . $this->esc((string) $max_steps) . '"></div>'
            . '<div class="col-md-3"><button type="submit" class="btn btn-success w-100">Save filter</button></div>'
            . '</div>'
            . '</form>'
            . '<p class="text-muted mt-2 mb-0">Choose an individual from this tree and a maximum relationship distance.</p>'
            . '</div>'
            . '</div>';
    }

    private function handleRecipientActionRequest(Tree $tree, int $block_id): string
    {
        $action = (string) ($_GET['potts_otd_recipient_action'] ?? '');
        if ($action === '') {
            return '';
        }

        if ((int) ($_GET['potts_otd_block_id'] ?? 0) !== $block_id) {
            return '';
        }

        if (!Auth::isManager($tree)) {
            return '<p class="alert alert-danger mb-3">Only a tree manager or administrator can manage recipients.</p>';
        }

        $recipients = $this->readRecipients();

        if ($action === 'add') {
            $email = trim((string) ($_GET['potts_otd_recipient_email'] ?? ''));
            $name = trim((string) ($_GET['potts_otd_recipient_name'] ?? ''));

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                return '<p class="alert alert-danger mb-3">That email address does not look valid.</p>';
            }

            foreach ($recipients as $recipient) {
                if (strcasecmp($recipient['email'], $email) === 0) {
                    return '<p class="alert alert-warning mb-3">That email address is already in the recipient list.</p>';
                }
            }

            $recipients[] = ['email' => $email, 'name' => $this->plain($name)];
            if (!$this->saveRecipients($recipients)) {
                return '<p class="alert alert-danger mb-3">Could not save the recipient list. Check that <code>modules_v4/potts_on_this_day_email/data/</code> is writable.</p>';
            }

            return '<p class="alert alert-success mb-3">Recipient added: <code>' . $this->esc($email) . '</code></p>';
        }

        if ($action === 'remove') {
            $email = trim((string) ($_GET['potts_otd_remove_email'] ?? ''));
            $new = [];
            $removed = false;

            foreach ($recipients as $recipient) {
                if (strcasecmp($recipient['email'], $email) === 0) {
                    $removed = true;
                    continue;
                }
                $new[] = $recipient;
            }

            if (!$removed) {
                return '<p class="alert alert-warning mb-3">That recipient was not found.</p>';
            }

            if (!$this->saveRecipients($new)) {
                return '<p class="alert alert-danger mb-3">Could not save the recipient list. Check that <code>modules_v4/potts_on_this_day_email/data/</code> is writable.</p>';
            }

            return '<p class="alert alert-success mb-3">Recipient removed: <code>' . $this->esc($email) . '</code></p>';
        }

        return '';
    }

    private function handleManualSend(Tree $tree, string $mode, Collection $facts, ?array $relationship_settings = null): string
    {
        $user = Auth::user();

        if ($mode === 'configured') {
            if (!Auth::isManager($tree)) {
                return '<p class="alert alert-danger mb-3">Only a tree manager or administrator can send to manual recipients.</p>';
            }

            $recipients = $this->readRecipients();
            if ($recipients === []) {
                return '<p class="alert alert-danger mb-3">No manual recipients were found.</p>';
            }
        } else {
            if ($user->id() <= 0) {
                return '<p class="alert alert-danger mb-3">You need to be signed in to send a test email to yourself.</p>';
            }

            $recipient_email = $user->email();
            if ($recipient_email === '' || filter_var($recipient_email, FILTER_VALIDATE_EMAIL) === false) {
                return '<p class="alert alert-danger mb-3">Your signed-in webtrees account email address is missing or invalid.</p>';
            }

            $recipients = [[
                'email' => $recipient_email,
                'name'  => $this->plain($user->realName()) ?: $this->plain($user->userName()),
            ]];
        }

        if ($facts->isEmpty()) {
            return '<p class="alert alert-info mb-3">No births, deaths or marriages were found for today, so no test email was sent.</p>';
        }

        $subject = 'On this day in the family tree - ' . $this->localDateHeading();
        $relationship_settings ??= $this->settings();
        $relationship_label = $mode === 'me' ? 'Relationship to you' : $this->relationshipLabelForSettings($tree, $relationship_settings);
        $text = $this->emailText($tree, $facts, $relationship_settings, $relationship_label);
        $html = $this->emailHtml($tree, $facts, $relationship_settings, $relationship_label);
        $sender = $this->senderFromSettings($this->settings(), $recipients[0]);
        $result = $this->sendToRecipients($sender, $recipients, $subject, $text, $html);

        if ($result['sent'] > 0) {
            $message = $mode === 'configured'
                ? 'Test email sent to ' . $result['sent'] . ' manual recipient(s).'
                : 'Test email sent to your webtrees account.';
            if ($result['failed'] !== []) {
                $message .= ' Failed: ' . implode('; ', $result['failed']);
            }
            return '<p class="alert alert-success mb-3">' . $this->esc($message) . '</p>';
        }

        return '<p class="alert alert-danger mb-3">The email was not sent. Check the webtrees email settings and error log. Failed: ' . $this->esc(implode('; ', $result['failed'])) . '</p>';
    }

    private function subscriberDetailsHtml(Tree $tree): string
    {
        $all = $this->readUserSettings();
        $tree_key = $tree->name();
        $tree_settings = $all[$tree_key] ?? [];

        if (!is_array($tree_settings) || $tree_settings === []) {
            return '<div class="card mb-3">'
                . '<div class="card-header"><strong>Registered user daily email subscribers</strong></div>'
                . '<div class="card-body small">'
                . '<p class="text-muted mb-0">No registered users have saved personal On This Day settings yet.</p>'
                . '</div>'
                . '</div>';
        }

        $rows = '';
        $count = 0;
        foreach ($tree_settings as $user_id => $settings) {
            if (!is_array($settings)) {
                continue;
            }

            if ((string) ($settings['daily_email_enabled'] ?? '0') !== '1') {
                continue;
            }

            $count++;
            $name = trim((string) ($settings['name'] ?? ''));
            $email = trim((string) ($settings['email'] ?? ''));
            $root_xref = strtoupper(trim((string) ($settings['relationship_root_xref'] ?? '')));
            $max_steps = (string) ($settings['relationship_max_steps'] ?? '4');
            $updated = trim((string) ($settings['updated'] ?? ''));
            $relationship_filter = (string) ($settings['relationship_filter_enabled'] ?? '1') === '1' ? 'On' : 'Off';

            $root_label = $root_xref !== '' ? $root_xref : '-';
            if ($root_xref !== '') {
                $root = Registry::individualFactory()->make($root_xref, $tree);
                if ($root instanceof Individual) {
                    $root_label = $this->plain($root->fullName()) . ' (' . $root->xref() . ')';
                } else {
                    $root_label = 'Not found: ' . $root_xref;
                }
            }

            $status_parts = [];
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $status_parts[] = 'invalid email';
            }
            if ($root_xref === '') {
                $status_parts[] = 'no root individual';
            } elseif (!Registry::individualFactory()->make($root_xref, $tree) instanceof Individual) {
                $status_parts[] = 'root not found';
            }

            $status = $status_parts === [] ? 'Ready' : 'Will be skipped: ' . implode(', ', $status_parts);
            $badge = $status_parts === []
                ? '<span class="badge bg-success">Ready</span>'
                : '<span class="badge bg-warning text-dark">' . $this->esc($status) . '</span>';

            $display_name = $name !== '' ? $name : '(no saved name)';
            $rows .= '<tr>'
                . '<td>' . $this->esc((string) $user_id) . '</td>'
                . '<td>' . $this->esc($display_name) . '</td>'
                . '<td>' . $this->esc($email !== '' ? $email : '-') . '</td>'
                . '<td>' . $this->esc($root_label) . '</td>'
                . '<td>' . $this->esc($relationship_filter) . '</td>'
                . '<td>' . $this->esc($max_steps) . '</td>'
                . '<td>' . ($updated !== '' ? $this->esc($updated) : '-') . '</td>'
                . '<td>' . $badge . '</td>'
                . '</tr>';
        }

        if ($count === 0) {
            $body = '<p class="text-muted mb-0">No registered users have ticked <strong>Email me daily</strong> for this tree yet.</p>';
        } else {
            $body = '<p class="mb-2">These are registered users who have ticked <strong>Email me daily</strong>. They receive their own personalised email when the daily email is sent.</p>'
                . '<div class="table-responsive">'
                . '<table class="table table-sm table-striped align-middle">'
                . '<thead><tr>'
                . '<th>User ID</th><th>Name</th><th>Email</th><th>Root individual</th><th>Filter</th><th>Steps</th><th>Updated</th><th>Status</th>'
                . '</tr></thead>'
                . '<tbody>' . $rows . '</tbody>'
                . '</table>'
                . '</div>';
        }

        return '<div class="card mb-3">'
            . '<div class="card-header"><strong>Registered user daily email subscribers</strong> <span class="badge bg-secondary">' . $this->esc((string) $count) . '</span></div>'
            . '<div class="card-body small">'
            . $body
            . '</div>'
            . '</div>';
    }

    private function recipientDetailsHtml(array $recipients, int $block_id): string
    {
        $relative = 'modules_v4/potts_on_this_day_email/data/recipients.txt';

        $items = '';
        if ($recipients === []) {
            $items = '<li class="text-muted">No valid recipients are configured yet.</li>';
        } else {
            foreach ($recipients as $recipient) {
                $label = $recipient['name'] !== ''
                    ? $recipient['name'] . ' <' . $recipient['email'] . '>'
                    : $recipient['email'];
                $remove_url = $this->currentPageUrl([
                    'potts_otd_recipient_action' => 'remove',
                    'potts_otd_block_id'         => (string) $block_id,
                    'potts_otd_remove_email'     => $recipient['email'],
                ]);

                $items .= '<li class="d-flex align-items-center justify-content-between gap-2 mb-1">'
                    . '<code>' . $this->esc($label) . '</code>'
                    . '<a class="btn btn-sm btn-outline-danger" href="' . $this->esc($remove_url) . '" onclick="return confirm(\'Remove this recipient?\')">Remove</a>'
                    . '</li>';
            }
        }

        return '<div class="card mb-3">'
            . '<div class="card-header"><strong>Manual email recipients, optional</strong></div>'
            . '<div class="card-body small">'
            . '<p class="mb-2">This optional list is for people you want to email manually even if they do not have their own webtrees login. Registered users should normally use their own Email me daily setting instead. This manual list is stored in <code>' . $this->esc($relative) . '</code>.</p>'
            . '<ul class="list-unstyled mb-3">' . $items . '</ul>'
            . '<form method="get" action="' . $this->esc($this->currentPagePath()) . '" class="border rounded p-3 bg-light">'
            . $this->currentQueryHiddenFields(['potts_otd_recipient_action' => 'add', 'potts_otd_block_id' => (string) $block_id])
            . '<div class="row g-2 align-items-end">'
            . '<div class="col-md-4"><label class="form-label" for="potts_otd_recipient_name">Name, optional</label><input class="form-control" id="potts_otd_recipient_name" name="potts_otd_recipient_name" type="text" placeholder="Example Person"></div>'
            . '<div class="col-md-5"><label class="form-label" for="potts_otd_recipient_email">Email address</label><input class="form-control" id="potts_otd_recipient_email" name="potts_otd_recipient_email" type="email" placeholder="name@example.com" required></div>'
            . '<div class="col-md-3"><button type="submit" class="btn btn-success w-100">Add recipient</button></div>'
            . '</div>'
            . '</form>'
            . '<p class="text-muted mt-2 mb-0">You can still edit the file manually in cPanel. Blank lines and lines starting with <code>#</code> are ignored.</p>'
            . '</div>'
            . '</div>';
    }

    private function schedulerDetailsHtml(Tree $tree, array $settings): string
    {
        $token = (string) ($settings['token'] ?? '');
        $opt_in_count = count($this->dailyEmailOptIns($tree));
        $last_run = (string) ($settings['last_run'] ?? '');
        $last_result = (string) ($settings['last_result'] ?? '');
        $last_count = (string) ($settings['last_count'] ?? '0');
        $last_recipients = (string) ($settings['last_recipients'] ?? '');
        $last_error = (string) ($settings['last_error'] ?? '');

        $configured = $token !== '' && $opt_in_count > 0;
        $status_rows = '';
        $status_rows .= '<tr><th scope="row">Daily email configured</th><td>' . ($configured ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-warning text-dark">Not yet</span>') . '</td></tr>';
        $status_rows .= '<tr><th scope="row">Daily date timezone</th><td>' . $this->esc($this->localTimezoneName() . ' (' . $this->localDateTime() . ')') . '</td></tr>';
        $status_rows .= '<tr><th scope="row">Registered user daily email opt-ins</th><td>' . $this->esc((string) $opt_in_count) . '</td></tr>';
        $historical_path = $this->historicalFactsDataPath();
        $historical_available = is_dir($historical_path);
        $status_rows .= '<tr><th scope="row">Historical context</th><td>' . ($historical_available ? '<span class="badge bg-success">Available</span>' : '<span class="badge bg-secondary">Not found</span>') . '</td></tr>';
        $last_attempt = (string) ($settings['last_scheduler_attempt'] ?? $settings['last_cron_attempt'] ?? '');
        $status_rows .= '<tr><th scope="row">Last daily email check</th><td>' . $this->esc($last_attempt !== '' ? $last_attempt : 'The daily email has not checked yet') . '</td></tr>';
        $status_rows .= '<tr><th scope="row">Last sent date</th><td>' . $this->esc($last_run !== '' ? $last_run : 'Not sent yet') . '</td></tr>';
        $status_rows .= '<tr><th scope="row">Last result</th><td>' . $this->esc($last_result !== '' ? $last_result : '-') . '</td></tr>';
        $status_rows .= '<tr><th scope="row">Events last sent</th><td>' . $this->esc($last_count) . '</td></tr>';
        $status_rows .= '<tr><th scope="row">Recipients last sent</th><td>' . $this->esc($last_recipients !== '' ? $last_recipients : '-') . '</td></tr>';
        if ($last_error !== '') {
            $status_rows .= '<tr><th scope="row">Last warning/error</th><td class="text-danger">' . $this->esc($last_error) . '</td></tr>';
        }

        $html = '<div class="card mb-3">'
            . '<div class="card-header"><strong>Daily email status</strong></div>'
            . '<div class="card-body small">'
            . '<table class="table table-sm mb-3"><tbody>' . $status_rows . '</tbody></table>';

        if ($token === '') {
            $html .= '<p class="alert alert-secondary mb-0">The secure scheduler link has not been prepared yet. Click <strong>Prepare secure scheduler link</strong> after confirming your own test email works.</p>';
        } else {
            $url = $this->runDailyUrl($tree, $token);
            $scheduler_command = "/usr/bin/curl -L -sS --fail '" . $url . "' >/dev/null 2>&1";
            $html .= '<p class="mb-1"><strong>Secure scheduler URL:</strong></p>'
                . '<textarea class="form-control mb-2" rows="3" readonly>' . $this->esc($url) . '</textarea>'
                . '<p class="mb-1"><strong>Scheduled task command:</strong></p>'
                . '<textarea class="form-control mb-2" rows="2" readonly>' . $this->esc($scheduler_command) . '</textarea>'
                . '<p class="mb-2 text-muted">Test the URL in your browser first. After the first test, add <code>&amp;force=1</code> only if you need to test it again on the same day.</p>'
                . '<p class="mb-0 text-muted">Daily email diagnostic log: <code>modules_v4/potts_on_this_day_email/data/scheduler.log</code></p>';
        }

        return $html . '</div></div>';
    }

    private function emailText(Tree $tree, Collection $facts, ?array $relationship_settings = null, string $relationship_label = ''): string
    {
        $lines = [];
        $lines[] = 'On this day in the family tree';
        $lines[] = $this->localDateHeading();
        $lines[] = '';

        if ($facts->isEmpty()) {
            $lines[] = 'No births, deaths or marriages were found for today.';
            $lines[] = '';
            $lines[] = $this->emailTurnOffText($tree);
            return implode(PHP_EOL, $lines);
        }

        foreach (['INDI:BIRT' => 'Births', 'INDI:DEAT' => 'Deaths', 'FAM:MARR' => 'Marriages'] as $tag => $heading) {
            $group = $facts->filter(static fn (Fact $fact): bool => $fact->tag() === $tag);
            if ($group->isEmpty()) {
                continue;
            }

            $lines[] = $heading;
            $lines[] = str_repeat('-', strlen($heading));

            foreach ($group as $fact) {
                $lines[] = $this->factTextLine($fact, true);
                $detail = $this->eventDetailText($fact);
                if ($detail !== '') {
                    $lines[] = $detail;
                }
                $relationship_note = $this->relationshipNoteText($tree, $fact, $relationship_settings, $relationship_label);
                if ($relationship_note !== '') {
                    $lines[] = $relationship_note;
                }
                $lines[] = 'View record: ' . $this->absoluteUrl($fact->record()->url());

                $context = $this->historicalContextForFact($fact, 2);
                if ($context !== []) {
                    $lines[] = 'Historical context:';
                    foreach ($context as $row) {
                        $lines[] = '  - ' . $row['display_date'] . ' - ' . $row['event_text'];
                        if ($row['link'] !== '') {
                            $lines[] = '    Source: ' . $row['link'];
                        }
                    }
                }

                $lines[] = '';
            }
        }

        $lines[] = $this->emailTurnOffText($tree);

        return implode(PHP_EOL, $lines);
    }

    private function emailHtml(Tree $tree, Collection $facts, ?array $relationship_settings = null, string $relationship_label = ''): string
    {
        $html = '<h2>On this day in the family tree</h2>';
        $html .= '<p>' . $this->esc($this->localDateHeading()) . '</p>';

        if ($facts->isEmpty()) {
            return $html . '<p>No births, deaths or marriages were found for today.</p>' . $this->emailTurnOffHtml($tree);
        }

        foreach (['INDI:BIRT' => 'Births', 'INDI:DEAT' => 'Deaths', 'FAM:MARR' => 'Marriages'] as $tag => $heading) {
            $group = $facts->filter(static fn (Fact $fact): bool => $fact->tag() === $tag);
            if ($group->isEmpty()) {
                continue;
            }

            $html .= '<h3>' . $this->esc($heading) . '</h3><ul>';
            foreach ($group as $fact) {
                $url = $this->absoluteUrl($fact->record()->url());
                $html .= '<li>' . $this->factHtmlLine($fact);
                $detail = $this->eventDetailText($fact);
                if ($detail !== '') {
                    $html .= '<br><span style="color:#555;font-size:0.95em">' . $this->esc($detail) . '</span>';
                }
                $relationship_note = $this->relationshipNoteHtml($tree, $fact, $relationship_settings, $relationship_label);
                if ($relationship_note !== '') {
                    $html .= '<br>' . $relationship_note;
                }
                $html .= '<br><a href="' . $this->esc($url) . '">View record</a>';

                $context = $this->historicalContextForFact($fact, 2);
                if ($context !== []) {
                    $html .= '<div style="margin-top:0.4em"><strong>Historical context</strong><ul>';
                    foreach ($context as $row) {
                        $source = $row['link'] !== ''
                            ? ' <a href="' . $this->esc($row['link']) . '">Source</a>'
                            : '';
                        $html .= '<li>' . $this->esc($row['display_date'] . ' - ' . $row['event_text']) . $source . '</li>';
                    }
                    $html .= '</ul></div>';
                }

                $html .= '</li>';
            }
            $html .= '</ul>';
        }

        return $html . $this->emailTurnOffHtml($tree);
    }

    private function emailTurnOffText(Tree $tree): string
    {
        $my_page_url = $this->myPageUrl($tree);

        return 'To change or turn off these daily emails, log in to the family tree and open My Page: ' . $my_page_url . PHP_EOL
            . 'In the My On This Day daily email block, untick Email me daily, then click Save.';
    }

    private function emailTurnOffHtml(Tree $tree): string
    {
        $my_page_url = $this->myPageUrl($tree);

        return '<hr><p style="font-size:0.95em;color:#555"><strong>Want to turn this off?</strong> '
            . 'Log in to the family tree and open <a href="' . $this->esc($my_page_url) . '">My Page</a>. '
            . 'In the <strong>My On This Day daily email</strong> block, untick <strong>Email me daily</strong>, then click <strong>Save</strong>.'
            . '</p>';
    }

    private function myPageUrl(Tree $tree): string
    {
        return $this->absoluteUrl('/index.php?route=%2Ftree%2F' . rawurlencode($tree->name()) . '%2Fuser-page');
    }

    private function factTextLine(Fact $fact, bool $bold_name = false): string
    {
        $parts = $this->factLineParts($fact);
        $name = $bold_name ? '**' . $parts['name'] . '**' : $parts['name'];
        $line = trim($parts['date'] . ' - ' . $name . ' ' . $parts['verb']);
        if ($parts['place'] !== '') {
            $line .= ' in ' . $parts['place'];
        }
        $line .= '.';

        return preg_replace('/\s+/', ' ', $line) ?? $line;
    }

    private function factHtmlLine(Fact $fact): string
    {
        $parts = $this->factLineParts($fact);
        $line = trim($this->esc($parts['date']) . ' - <strong>' . $this->esc($parts['name']) . '</strong> ' . $this->esc($parts['verb']));
        if ($parts['place'] !== '') {
            $line .= ' in ' . $this->esc($parts['place']);
        }
        $line .= '.';

        return $line;
    }

    /**
     * @return array{date:string,name:string,verb:string,place:string}
     */
    private function factLineParts(Fact $fact): array
    {
        $record = $this->plain($fact->record()->fullName());
        $date = $this->plain($fact->date()->display());
        $place = $this->plain($fact->place()->gedcomName());

        $verb = match ($fact->tag()) {
            'INDI:BIRT' => 'was born',
            'INDI:DEAT' => 'died',
            'FAM:MARR'  => 'were married',
            default     => strtolower($this->plain($fact->label())),
        };

        return [
            'date' => $date,
            'name' => $record,
            'verb' => $verb,
            'place' => $place,
        ];
    }

    /**
     * Return historical facts for the same year and country/region as a family-tree event.
     * The CSV files are read from the sibling potts_historical_facts module.
     *
     * @return array<int, array<string, string>>
     */
    private function filterFactsByRelationship(Tree $tree, Collection $facts, ?array $settings = null): Collection
    {
        $settings ??= $this->settings();
        if (!$this->relationshipFilterEnabled($settings)) {
            return $facts;
        }

        $root_xref = $this->relationshipRootXref($settings, '');
        if ($root_xref === '') {
            return $facts;
        }

        $max_steps = $this->relationshipMaxSteps($settings);
        $root = Registry::individualFactory()->make($root_xref, $tree);

        if (!$root instanceof Individual) {
            // Keep the email useful rather than hiding every event if the root ID is wrong.
            return $facts;
        }

        return $facts->filter(function (Fact $fact) use ($tree, $root_xref, $max_steps): bool {
            foreach ($this->individualsForFact($fact) as $individual) {
                if ($this->relationshipDistance($tree, $root_xref, $individual, $max_steps) !== null) {
                    return true;
                }
            }
            return false;
        })->values();
    }

    /**
     * @return array<int, Individual>
     */
    private function individualsForFact(Fact $fact): array
    {
        $record = $fact->record();

        if ($record instanceof Individual) {
            return [$record];
        }

        if ($record instanceof Family) {
            $individuals = [];
            foreach ($record->spouses(Auth::PRIV_HIDE) as $spouse) {
                if ($spouse instanceof Individual) {
                    $individuals[$spouse->xref()] = $spouse;
                }
            }
            return array_values($individuals);
        }

        return [];
    }

    private function relationshipDistance(Tree $tree, string $root_xref, Individual $target, int $max_steps): ?int
    {
        $cache_key = $tree->id() . ':' . $root_xref . ':' . $target->xref() . ':' . $max_steps;
        if (array_key_exists($cache_key, $this->relationship_distance_cache)) {
            return $this->relationship_distance_cache[$cache_key];
        }

        $path = $this->relationshipPath($tree, $root_xref, $target, $max_steps);
        $distance = $path === null ? null : count($path);
        $this->relationship_distance_cache[$cache_key] = $distance;

        return $distance;
    }

    /**
     * Return a short relationship path from the root to the target.
     * The path uses simple family movements: parent, child, spouse and sibling.
     *
     * @return array<int, string>|null
     */
    private function relationshipPath(Tree $tree, string $root_xref, Individual $target, int $max_steps): ?array
    {
        $cache_key = $tree->id() . ':' . $root_xref . ':' . $target->xref() . ':' . $max_steps;
        if (array_key_exists($cache_key, $this->relationship_path_cache)) {
            return $this->relationship_path_cache[$cache_key];
        }

        $root = Registry::individualFactory()->make($root_xref, $tree);
        if (!$root instanceof Individual) {
            $this->relationship_path_cache[$cache_key] = null;
            return null;
        }

        if ($root->xref() === $target->xref()) {
            $this->relationship_path_cache[$cache_key] = [];
            return [];
        }

        $visited_individuals = [$root->xref() => true];
        $queue = [[$root, []]];
        $head = 0;

        while (isset($queue[$head])) {
            [$individual, $path] = $queue[$head];
            $head++;

            if (count($path) >= $max_steps) {
                continue;
            }

            foreach ($this->relationshipNeighbours($individual) as $xref => $item) {
                if (isset($visited_individuals[$xref])) {
                    continue;
                }

                $related = $item['individual'];
                $next_path = array_merge($path, [$item['label']]);

                if ($related->xref() === $target->xref()) {
                    $this->relationship_path_cache[$cache_key] = $next_path;
                    return $next_path;
                }

                $visited_individuals[$xref] = true;
                $queue[] = [$related, $next_path];
            }
        }

        $this->relationship_path_cache[$cache_key] = null;
        return null;
    }

    /**
     * @return array<string, array{individual: Individual, label: string}>
     */
    private function relationshipNeighbours(Individual $individual): array
    {
        $neighbours = [];

        foreach ($individual->childFamilies(Auth::PRIV_HIDE) as $family) {
            if (!$family instanceof Family) {
                continue;
            }

            foreach ($family->spouses(Auth::PRIV_HIDE) as $related) {
                if ($related instanceof Individual && $related->xref() !== $individual->xref()) {
                    $neighbours[$related->xref()] ??= ['individual' => $related, 'label' => 'parent'];
                }
            }

            foreach ($family->children(Auth::PRIV_HIDE) as $related) {
                if ($related instanceof Individual && $related->xref() !== $individual->xref()) {
                    $neighbours[$related->xref()] ??= ['individual' => $related, 'label' => 'sibling'];
                }
            }
        }

        foreach ($individual->spouseFamilies(Auth::PRIV_HIDE) as $family) {
            if (!$family instanceof Family) {
                continue;
            }

            foreach ($family->spouses(Auth::PRIV_HIDE) as $related) {
                if ($related instanceof Individual && $related->xref() !== $individual->xref()) {
                    $neighbours[$related->xref()] ??= ['individual' => $related, 'label' => 'spouse'];
                }
            }

            foreach ($family->children(Auth::PRIV_HIDE) as $related) {
                if ($related instanceof Individual && $related->xref() !== $individual->xref()) {
                    $neighbours[$related->xref()] ??= ['individual' => $related, 'label' => 'child'];
                }
            }
        }

        return $neighbours;
    }

    private function relationshipLabelForSettings(Tree $tree, ?array $settings): string
    {
        $settings ??= $this->settings();
        $root_xref = $this->relationshipRootXref($settings, '');
        if ($root_xref === '') {
            return 'Relationship';
        }

        $root = Registry::individualFactory()->make($root_xref, $tree);
        if (!$root instanceof Individual) {
            return 'Relationship to root person';
        }

        return 'Relationship to ' . $this->plain($root->fullName());
    }

    private function relationshipNoteText(Tree $tree, Fact $fact, ?array $settings, string $relationship_label): string
    {
        $items = $this->relationshipNoteItems($tree, $fact, $settings, $relationship_label);
        if ($items === []) {
            return '';
        }

        $sentences = [];
        $relationship_links = [];
        $common_ancestor_lines = [];

        foreach ($items as $item) {
            $sentences[] = $item['sentence'];
            if ($item['url'] !== '') {
                $relationship_links[] = $item['url'];
            }
            if ($item['common_ancestors'] !== '') {
                $common_ancestor_lines[$item['common_ancestors']] = true;
            }
        }

        $text = 'Relationship: ' . implode('; ', $sentences);
        if ($relationship_links !== []) {
            $text .= PHP_EOL . 'Relationship chart: ' . implode(PHP_EOL . 'Relationship chart: ', array_values(array_unique($relationship_links)));
        }
        if ($common_ancestor_lines !== []) {
            $text .= PHP_EOL . '(Common ancestors: ' . implode('; ', array_keys($common_ancestor_lines)) . ')';
        }

        return $text;
    }

    private function relationshipNoteHtml(Tree $tree, Fact $fact, ?array $settings, string $relationship_label): string
    {
        $items = $this->relationshipNoteItems($tree, $fact, $settings, $relationship_label);
        if ($items === []) {
            return '';
        }

        $sentences = [];
        $common_ancestor_lines = [];

        foreach ($items as $item) {
            $sentence = $this->esc($item['sentence']);
            if ($item['url'] !== '') {
                $sentence = '<a href="' . $this->esc($item['url']) . '">' . $sentence . '</a>';
            }
            $sentences[] = $sentence;
            if ($item['common_ancestors'] !== '') {
                $common_ancestor_lines[$item['common_ancestors']] = true;
            }
        }

        $html = '<span style="color:#555;font-size:0.95em">Relationship: ' . implode('; ', $sentences);
        if ($common_ancestor_lines !== []) {
            $html .= '<br><span style="color:#777">(Common ancestors: ' . $this->esc(implode('; ', array_keys($common_ancestor_lines))) . ')</span>';
        }
        $html .= '</span>';

        return $html;
    }

    /**
     * @return array<int, array{sentence: string, url: string, common_ancestors: string}>
     */
    private function relationshipNoteItems(Tree $tree, Fact $fact, ?array $settings, string $relationship_label): array
    {
        $settings ??= $this->settings();
        $root_xref = $this->relationshipRootXref($settings, '');
        if ($root_xref === '') {
            return [];
        }

        $root = Registry::individualFactory()->make($root_xref, $tree);
        if (!$root instanceof Individual) {
            return [];
        }

        $max_steps = $this->relationshipMaxSteps($settings);
        $root_name = $this->plain($root->fullName());
        $to_you = $relationship_label === 'Relationship to you';
        $items = [];

        foreach ($this->individualsForFact($fact) as $individual) {
            $path = $this->relationshipPath($tree, $root_xref, $individual, $max_steps);
            if ($path === null) {
                continue;
            }

            $name = $this->plain($individual->fullName());
            $description = $this->relationshipDescription($path, $individual);
            $items[] = [
                'sentence' => $this->relationshipSentence($name, $description, $root_name, $to_you),
                'url' => $this->relationshipChartUrl($tree, $root, $individual),
                'common_ancestors' => $this->commonAncestorText($tree, $root, $individual, max(8, $max_steps + 4)),
            ];
        }

        return $items;
    }

    private function relationshipSentence(string $target_name, string $description, string $root_name, bool $to_you): string
    {
        if ($description === 'self') {
            return $to_you ? $target_name . ' is you.' : $target_name . ' is ' . $root_name . '.';
        }

        if ($to_you) {
            return $target_name . ' is your ' . $description . '.';
        }

        return $target_name . ' is ' . $this->possessiveName($root_name) . ' ' . $description . '.';
    }

    private function relationshipChartUrl(Tree $tree, Individual $root, Individual $target): string
    {
        if ($root->xref() === '' || $target->xref() === '') {
            return '';
        }

        $route = '/tree/' . $tree->name() . '/relationships-0-0/' . $root->xref() . '/' . $target->xref();

        return $this->absoluteUrl('/index.php?route=' . rawurlencode($route));
    }

    private function possessiveName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return "the root person's";
        }

        return str_ends_with(strtolower($name), 's') ? $name . "'" : $name . "'s";
    }

    private function commonAncestorText(Tree $tree, Individual $root, Individual $target, int $max_depth): string
    {
        if ($root->xref() === $target->xref()) {
            return '';
        }

        $root_ancestors = $this->ancestorMap($root, $max_depth);
        $target_ancestors = $this->ancestorMap($target, $max_depth);
        $best_total = null;
        $common = [];

        foreach ($root_ancestors as $xref => $root_item) {
            if (!isset($target_ancestors[$xref])) {
                continue;
            }

            if ($xref === $root->xref() || $xref === $target->xref()) {
                continue;
            }

            $total = $root_item['depth'] + $target_ancestors[$xref]['depth'];
            if ($best_total === null || $total < $best_total) {
                $best_total = $total;
                $common = [];
            }

            if ($total === $best_total) {
                $individual = $root_item['individual'];
                if ($individual instanceof Individual) {
                    $common[$xref] = $this->plain($individual->fullName());
                }
            }
        }

        if ($common === []) {
            return '';
        }

        return implode(' + ', array_values($common));
    }

    /**
     * @return array<string, array{individual: Individual, depth: int}>
     */
    private function ancestorMap(Individual $individual, int $max_depth): array
    {
        $map = [$individual->xref() => ['individual' => $individual, 'depth' => 0]];
        $queue = [[$individual, 0]];
        $head = 0;

        while (isset($queue[$head])) {
            [$current, $depth] = $queue[$head];
            $head++;

            if ($depth >= $max_depth) {
                continue;
            }

            foreach ($current->childFamilies(Auth::PRIV_HIDE) as $family) {
                if (!$family instanceof Family) {
                    continue;
                }

                foreach ($family->spouses(Auth::PRIV_HIDE) as $parent) {
                    if (!$parent instanceof Individual) {
                        continue;
                    }

                    if (!isset($map[$parent->xref()]) || $map[$parent->xref()]['depth'] > $depth + 1) {
                        $map[$parent->xref()] = ['individual' => $parent, 'depth' => $depth + 1];
                        $queue[] = [$parent, $depth + 1];
                    }
                }
            }
        }

        return $map;
    }

    /**
     * @param array<int, string> $path
     */
    private function allPathLabelsAre(array $path, string $label): bool
    {
        if ($path === []) {
            return false;
        }

        foreach ($path as $path_label) {
            if ($path_label !== $label) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, string> $path
     */
    private function relationshipDescription(array $path, Individual $target): string
    {
        $length = count($path);

        if ($length === 0) {
            return 'self';
        }

        if ($this->allPathLabelsAre($path, 'parent')) {
            return $this->ancestorRelationshipName($length, $target);
        }

        if ($this->allPathLabelsAre($path, 'child')) {
            return $this->descendantRelationshipName($length, $target);
        }

        if ($this->isParentThenSiblingPath($path)) {
            return $this->auntUncleRelationshipName(count($path) - 1, $target);
        }

        if ($this->isSiblingThenChildPath($path)) {
            return $this->nieceNephewRelationshipName(count($path) - 1, $target);
        }

        $cousin = $this->cousinRelationshipName($path);
        if ($cousin !== null) {
            return $cousin;
        }

        $key = implode('>', $path);
        $specific = match ($key) {
            'parent' => $this->genderedRelationshipName($target, 'father', 'mother', 'parent'),
            'child' => $this->genderedRelationshipName($target, 'son', 'daughter', 'child'),
            'spouse' => $this->genderedRelationshipName($target, 'husband', 'wife', 'spouse'),
            'sibling' => $this->genderedRelationshipName($target, 'brother', 'sister', 'sibling'),
            'spouse>parent' => $this->genderedRelationshipName($target, 'father-in-law', 'mother-in-law', 'parent-in-law'),
            'child>spouse' => $this->genderedRelationshipName($target, 'son-in-law', 'daughter-in-law', 'child-in-law'),
            'spouse>sibling', 'sibling>spouse' => $this->genderedRelationshipName($target, 'brother-in-law', 'sister-in-law', 'sibling-in-law'),
            'parent>spouse' => $this->genderedRelationshipName($target, 'stepfather', 'stepmother', 'step-parent'),
            'spouse>child' => $this->genderedRelationshipName($target, 'stepson', 'stepdaughter', 'stepchild'),
            default => null,
        };

        if ($specific !== null) {
            return $specific;
        }

        if (end($path) === 'spouse' && count($path) > 1) {
            $base_path = $path;
            array_pop($base_path);
            $base = $this->relationshipDescription($base_path, $target);
            if ($base !== 'self') {
                return $this->possessiveRelationship($base) . ' ' . $this->genderedRelationshipName($target, 'husband', 'wife', 'spouse');
            }
        }

        if ($path[0] === 'spouse' && count($path) > 1) {
            $base_path = $path;
            array_shift($base_path);
            $base = $this->relationshipDescription($base_path, $target);
            if ($base !== 'self') {
                return "spouse's " . $base;
            }
        }

        return 'relative within ' . $length . ' step' . ($length === 1 ? '' : 's');
    }

    /**
     * @param array<int, string> $path
     */
    private function isParentThenSiblingPath(array $path): bool
    {
        if (count($path) < 2 || end($path) !== 'sibling') {
            return false;
        }

        array_pop($path);
        return $this->allPathLabelsAre($path, 'parent');
    }

    /**
     * @param array<int, string> $path
     */
    private function isSiblingThenChildPath(array $path): bool
    {
        if (count($path) < 2 || $path[0] !== 'sibling') {
            return false;
        }

        array_shift($path);
        return $this->allPathLabelsAre($path, 'child');
    }


    private function ancestorRelationshipName(int $generations, Individual $target): string
    {
        if ($generations === 1) {
            return $this->genderedRelationshipName($target, 'father', 'mother', 'parent');
        }

        if ($generations === 2) {
            return $this->genderedRelationshipName($target, 'grandfather', 'grandmother', 'grandparent');
        }

        $great_count = $generations - 2;
        $prefix = $this->greatPrefix($great_count);
        return $this->genderedRelationshipName($target, $prefix . 'grandfather', $prefix . 'grandmother', $prefix . 'grandparent');
    }

    private function descendantRelationshipName(int $generations, Individual $target): string
    {
        if ($generations === 1) {
            return $this->genderedRelationshipName($target, 'son', 'daughter', 'child');
        }

        if ($generations === 2) {
            return $this->genderedRelationshipName($target, 'grandson', 'granddaughter', 'grandchild');
        }

        $great_count = $generations - 2;
        $prefix = $this->greatPrefix($great_count);
        return $this->genderedRelationshipName($target, $prefix . 'grandson', $prefix . 'granddaughter', $prefix . 'grandchild');
    }

    private function genderedRelationshipName(Individual $individual, string $male, string $female, string $unknown): string
    {
        try {
            $sex = strtoupper((string) $individual->sex());
        } catch (Throwable) {
            $sex = '';
        }

        return match ($sex) {
            'M' => $male,
            'F' => $female,
            default => $unknown,
        };
    }

    private function auntUncleRelationshipName(int $parent_steps_before_sibling, Individual $target): string
    {
        $great_count = max(0, $parent_steps_before_sibling - 1);
        $base_male = $this->greatPrefix($great_count) . 'uncle';
        $base_female = $this->greatPrefix($great_count) . 'aunt';
        $base_unknown = $this->greatPrefix($great_count) . 'aunt/uncle';

        return $this->genderedRelationshipName($target, $base_male, $base_female, $base_unknown);
    }

    private function nieceNephewRelationshipName(int $child_steps_after_sibling, Individual $target): string
    {
        $great_count = max(0, $child_steps_after_sibling - 1);
        $base_male = $this->greatPrefix($great_count) . 'nephew';
        $base_female = $this->greatPrefix($great_count) . 'niece';
        $base_unknown = $this->greatPrefix($great_count) . 'niece/nephew';

        return $this->genderedRelationshipName($target, $base_male, $base_female, $base_unknown);
    }

    private function greatPrefix(int $great_count): string
    {
        if ($great_count <= 0) {
            return '';
        }

        if ($great_count === 1) {
            return 'great ';
        }

        return 'great ×' . $great_count . ' ';
    }

    /**
     * @param array<int, string> $path
     */
    private function cousinRelationshipName(array $path): ?string
    {
        $sibling_index = array_search('sibling', $path, true);
        if ($sibling_index === false) {
            return null;
        }

        $before = array_slice($path, 0, (int) $sibling_index);
        $after = array_slice($path, (int) $sibling_index + 1);

        if (!$this->allPathLabelsAre($before, 'parent') || !$this->allPathLabelsAre($after, 'child')) {
            return null;
        }

        $up = count($before);
        $down = count($after);

        if ($up < 1 || $down < 1) {
            return null;
        }

        $degree = min($up, $down);
        if ($degree < 1) {
            return null;
        }

        $removed = abs($up - $down);
        $relationship = $this->ordinalWord($degree) . ' cousin';

        if ($removed === 1) {
            $relationship .= ' once removed';
        } elseif ($removed === 2) {
            $relationship .= ' twice removed';
        } elseif ($removed > 2) {
            $relationship .= ' ' . $removed . ' times removed';
        }

        return $relationship;
    }

    private function ordinalWord(int $number): string
    {
        return match ($number) {
            1 => 'first',
            2 => 'second',
            3 => 'third',
            4 => 'fourth',
            5 => 'fifth',
            6 => 'sixth',
            7 => 'seventh',
            8 => 'eighth',
            9 => 'ninth',
            10 => 'tenth',
            default => $this->ordinalNumber($number),
        };
    }

    private function ordinalNumber(int $number): string
    {
        $abs = abs($number);
        $mod100 = $abs % 100;
        if ($mod100 >= 11 && $mod100 <= 13) {
            return $number . 'th';
        }

        return $number . match ($abs % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }

    private function possessiveRelationship(string $relationship): string
    {
        $relationship = trim($relationship);
        if ($relationship === '') {
            return "relative's";
        }

        return str_ends_with(strtolower($relationship), 's') ? $relationship . "'" : $relationship . "'s";
    }

    private function eventDetailText(Fact $fact): string
    {
        return match ($fact->tag()) {
            'INDI:BIRT' => $this->birthDetailText($fact),
            'INDI:DEAT' => $this->deathDetailText($fact),
            'FAM:MARR'  => $this->marriageDetailText($fact),
            default     => '',
        };
    }

    private function birthDetailText(Fact $fact): string
    {
        $record = $fact->record();
        if (!$record instanceof Individual) {
            return '';
        }

        $name = $this->firstName($record);
        $birth_year = $this->factYear($fact);
        if ($birth_year === null) {
            return '';
        }

        $current_year = $this->localCurrentYear();
        $birthday_age = $current_year - $birth_year;
        if ($birthday_age < 0) {
            return '';
        }

        $death_year = $this->individualDeathYear($record);
        if ($death_year !== null) {
            $age_at_death = $death_year - $birth_year;
            $years_since_death = $current_year - $death_year;
            $parts = [$name . ' would have been ' . $birthday_age . ' today'];
            if ($age_at_death >= 0) {
                $parts[] = $this->pronoun($record, 'He', 'She', 'They') . ' died aged ' . $age_at_death;
            }
            if ($years_since_death >= 0) {
                $parts[] = $this->possessivePronoun($record, 'His', 'Her', 'Their') . ' death was ' . $years_since_death . ' year' . ($years_since_death === 1 ? '' : 's') . ' ago';
            }
            return implode('. ', $parts) . '.';
        }

        return $name . ' turns ' . $birthday_age . ' today.';
    }

    private function deathDetailText(Fact $fact): string
    {
        $record = $fact->record();
        if (!$record instanceof Individual) {
            return '';
        }

        $name = $this->firstName($record);
        $death_year = $this->factYear($fact);
        if ($death_year === null) {
            return '';
        }

        $current_year = $this->localCurrentYear();
        $birth_year = $this->individualBirthYear($record);
        $parts = [];

        if ($birth_year !== null) {
            $age_at_death = $death_year - $birth_year;
            if ($age_at_death >= 0) {
                $parts[] = $name . ' was ' . $age_at_death . ' when ' . $this->pronoun($record, 'he', 'she', 'they') . ' died';
            }
        }

        $years_since_death = $current_year - $death_year;
        if ($years_since_death >= 0) {
            $parts[] = $this->possessivePronoun($record, 'His', 'Her', 'Their') . ' death was ' . $years_since_death . ' year' . ($years_since_death === 1 ? '' : 's') . ' ago';
        }

        return $parts === [] ? '' : implode('. ', $parts) . '.';
    }

    private function marriageDetailText(Fact $fact): string
    {
        $record = $fact->record();
        if (!$record instanceof Family) {
            return '';
        }

        $marriage_year = $this->factYear($fact);
        if ($marriage_year === null) {
            return '';
        }

        $current_year = $this->localCurrentYear();
        $anniversary = $current_year - $marriage_year;
        if ($anniversary < 0) {
            return '';
        }

        $spouses = [];
        foreach ($record->spouses(Auth::PRIV_HIDE) as $spouse) {
            if ($spouse instanceof Individual) {
                $spouses[] = $spouse;
            }
        }

        $prefix = 'Today is their ' . $this->ordinalNumber($anniversary) . ' wedding anniversary.';
        if ($spouses === []) {
            return $prefix;
        }

        $earliest_death_year = null;
        $earliest_deceased = null;
        foreach ($spouses as $spouse) {
            $death_year = $this->individualDeathYear($spouse);
            if ($death_year !== null && ($earliest_death_year === null || $death_year < $earliest_death_year)) {
                $earliest_death_year = $death_year;
                $earliest_deceased = $spouse;
            }
        }

        if ($earliest_death_year !== null && $earliest_deceased instanceof Individual) {
            $married_years = max(0, $earliest_death_year - $marriage_year);
            $years_since_death = $current_year - $earliest_death_year;
            $death_note = $years_since_death >= 0 ? ' That was ' . $years_since_death . ' year' . ($years_since_death === 1 ? '' : 's') . ' ago.' : '';
            return 'Today would have been their ' . $this->ordinalNumber($anniversary) . ' wedding anniversary. They were married for ' . $married_years . ' year' . ($married_years === 1 ? '' : 's') . ' until ' . $this->firstName($earliest_deceased) . ' died in ' . $earliest_death_year . '.' . $death_note;
        }

        return $prefix;
    }

    private function localCurrentYear(): int
    {
        return (int) $this->localNow()->format('Y');
    }

    private function individualBirthYear(Individual $individual): ?int
    {
        try {
            $display = $this->plain($individual->getBirthDate()->display());
        } catch (Throwable) {
            return null;
        }

        return $this->yearFromText($display);
    }

    private function individualDeathYear(Individual $individual): ?int
    {
        try {
            $display = $this->plain($individual->getDeathDate()->display());
        } catch (Throwable) {
            return null;
        }

        return $this->yearFromText($display);
    }

    private function yearFromText(string $text): ?int
    {
        if (preg_match('/(1[0-9]{3}|2[0-9]{3})/', $text, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function firstName(Individual $individual): string
    {
        $name = $this->plain($individual->fullName());
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
        if ($name === '') {
            return 'This person';
        }

        $parts = explode(' ', $name);
        return $parts[0] !== '' ? $parts[0] : $name;
    }

    private function pronoun(Individual $individual, string $male, string $female, string $unknown): string
    {
        try {
            $sex = strtoupper((string) $individual->sex());
        } catch (Throwable) {
            $sex = '';
        }

        return match ($sex) {
            'M' => $male,
            'F' => $female,
            default => $unknown,
        };
    }

    private function possessivePronoun(Individual $individual, string $male, string $female, string $unknown): string
    {
        return $this->pronoun($individual, $male, $female, $unknown);
    }

    private function personalPreviewEnabled(array $settings): bool
    {
        return (string) ($settings['personal_preview_enabled'] ?? '0') === '1';
    }

    private function dailyEmailEnabled(array $settings): bool
    {
        return (string) ($settings['daily_email_enabled'] ?? '0') === '1';
    }

    private function relationshipFilterEnabled(array $settings): bool
    {
        return (string) ($settings['relationship_filter_enabled'] ?? '1') === '1';
    }

    private function relationshipRootXref(array $settings, string $default = ''): string
    {
        $xref = strtoupper(trim((string) ($settings['relationship_root_xref'] ?? $default)));
        return $xref !== '' ? $xref : $default;
    }

    private function relationshipMaxSteps(array $settings): int
    {
        $max_steps = (int) ($settings['relationship_max_steps'] ?? 4);
        return max(0, min(20, $max_steps));
    }

    private function historicalContextForFact(Fact $fact, int $limit = 2): array
    {
        $year = $this->factYear($fact);
        if ($year === null) {
            return [];
        }

        $csv = $this->historicalCsvPathForFact($fact);
        if ($csv === null || !is_file($csv)) {
            return [];
        }

        $facts_by_year = $this->historicalFactsByYear($csv);
        $rows = $facts_by_year[$year] ?? [];

        if ($rows === []) {
            return [];
        }

        return array_slice($rows, 0, $limit);
    }

    private function factYear(Fact $fact): ?int
    {
        $date = $this->plain($fact->date()->display());
        if (preg_match('/\b(1[0-9]{3}|2[0-9]{3})\b/', $date, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function historicalCsvPathForFact(Fact $fact): ?string
    {
        $place = $this->plain($fact->place()->gedcomName());
        $file = $this->historicalCsvFileForPlace($place);

        if ($file === null) {
            return null;
        }

        return $this->historicalFactsDataPath() . '/' . $file;
    }

    private function historicalFactsDataPath(): string
    {
        return dirname(__DIR__) . '/potts_historical_facts/resources/data';
    }

    private function historicalCsvFileForPlace(string $place): ?string
    {
        if ($place === '') {
            return null;
        }

        $normalised = strtolower($place);
        $normalised = str_replace(['.', '  '], ['', ' '], $normalised);
        $parts = array_map(static fn (string $part): string => trim(strtolower($part)), explode(',', $normalised));

        $contains = static function (array $needles) use ($normalised, $parts): bool {
            foreach ($needles as $needle) {
                $needle = strtolower($needle);
                if (in_array($needle, $parts, true) || str_contains($normalised, $needle)) {
                    return true;
                }
            }
            return false;
        };

        if ($contains(['australia'])) {
            return 'en_AU.csv';
        }
        if ($contains(['new zealand'])) {
            return 'en_NZ.csv';
        }
        if ($contains(['united states', 'united states of america', 'usa', 'u s a', 'america'])) {
            return 'en_US.csv';
        }
        if ($contains(['england'])) {
            return 'en_ENG.csv';
        }
        if ($contains(['scotland'])) {
            return 'en_SCT.csv';
        }
        if ($contains(['wales'])) {
            return 'en_WLS.csv';
        }
        if ($contains(['ireland', 'northern ireland'])) {
            return 'en_IE.csv';
        }
        if ($contains(['united kingdom', 'great britain', 'britain'])) {
            return 'en_GB.csv';
        }
        if ($contains(['canada'])) {
            return 'en_CA.csv';
        }
        if ($contains(['south africa'])) {
            return 'en_ZA.csv';
        }
        if ($contains(['germany'])) {
            return 'en_DE.csv';
        }
        if ($contains(['france'])) {
            return 'en_FR.csv';
        }
        if ($contains(['italy'])) {
            return 'en_IT.csv';
        }
        if ($contains(['china'])) {
            return 'en_CN.csv';
        }
        if ($contains(['india'])) {
            return 'en_IN.csv';
        }
        if ($contains(['greece'])) {
            return 'en_GR.csv';
        }
        if ($contains(['malta'])) {
            return 'en_MT.csv';
        }
        if ($contains(['netherlands', 'holland'])) {
            return 'en_NL.csv';
        }

        return null;
    }

    /**
     * @return array<int, array<int, array<string, string>>>
     */
    private function historicalFactsByYear(string $csv): array
    {
        if (isset($this->historical_context_cache[$csv])) {
            return $this->historical_context_cache[$csv];
        }

        $by_year = [];
        $handle = fopen($csv, 'rb');
        if ($handle === false) {
            $this->historical_context_cache[$csv] = [];
            return [];
        }

        while (($row = fgetcsv($handle, 0, ';')) !== false) {
            if ($row === [] || str_starts_with(trim((string) ($row[0] ?? '')), '#')) {
                continue;
            }

            $date = trim((string) ($row[0] ?? ''));
            $event_text = trim((string) ($row[2] ?? ''));
            $link = trim((string) ($row[3] ?? ''));
            $category = trim((string) ($row[4] ?? ''));
            $year = $this->historicalDateYear($date);

            if ($year === null || $event_text === '') {
                continue;
            }

            $by_year[$year][] = [
                'display_date' => $date,
                'year' => (string) $year,
                'event_text' => $event_text,
                'link' => $link,
                'category' => $category,
            ];
        }
        fclose($handle);

        foreach ($by_year as $year => $rows) {
            usort($rows, static fn (array $a, array $b): int => strcmp($a['display_date'], $b['display_date']));
            $by_year[$year] = $rows;
        }

        $this->historical_context_cache[$csv] = $by_year;

        return $by_year;
    }

    private function historicalDateYear(string $date): ?int
    {
        if (stripos($date, 'BCE') !== false || stripos($date, 'BC') !== false) {
            return null;
        }

        if (preg_match('/\b(1[0-9]{3}|2[0-9]{3})\b/', $date, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    private function sendToRecipients(UserInterface $sender, array $recipients, string $subject, string $text, string $html): array
    {
        $sent = 0;
        $failed = [];

        foreach ($recipients as $recipient_data) {
            $recipient = $this->emailUser($recipient_data['email'], $recipient_data['name'] !== '' ? $recipient_data['name'] : $recipient_data['email']);
            $ok = Registry::container()->get(EmailService::class)->send($sender, $recipient, $sender, $subject, $text, $html);
            if ($ok) {
                $sent++;
            } else {
                $failed[] = $recipient_data['email'];
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    private function readRecipients(): array
    {
        $path = $this->recipientsPath();
        if (!is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $recipients = [];
        $seen = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parsed = $this->parseRecipientLine($line);
            if ($parsed === null) {
                continue;
            }

            $key = strtolower($parsed['email']);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $recipients[] = $parsed;
        }

        return $recipients;
    }

    private function saveRecipients(array $recipients): bool
    {
        $dir = dirname($this->recipientsPath());
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $this->ensureHtaccess();

        $lines = [
            '# Potts On This Day Email recipients',
            '# One recipient per line. This file can be edited here or in the webtrees block GUI.',
            '# Examples:',
            '# Example Person <person@example.com>',
            '# person@example.com',
            '# person@example.com|Example Person',
            '',
        ];

        $seen = [];
        foreach ($recipients as $recipient) {
            $email = trim((string) ($recipient['email'] ?? ''));
            $name = trim((string) ($recipient['name'] ?? ''));

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $key = strtolower($email);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $lines[] = $name !== '' ? $name . ' <' . $email . '>' : $email;
        }

        return file_put_contents($this->recipientsPath(), implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) !== false;
    }

    private function parseRecipientLine(string $line): ?array
    {
        $name = '';
        $email = '';

        if (preg_match('/^(.+?)\s*<([^>]+)>$/', $line, $matches) === 1) {
            $name = trim($matches[1], " \t\n\r\0\x0B\"'");
            $email = trim($matches[2]);
        } elseif (str_contains($line, '|')) {
            [$email, $name] = array_map('trim', explode('|', $line, 2));
        } else {
            $email = trim($line);
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return ['email' => $email, 'name' => $name];
    }

    private function ensureRecipientsFile(string $email, string $name): bool
    {
        $dir = dirname($this->recipientsPath());
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $this->ensureHtaccess();

        if (is_file($this->recipientsPath()) && trim((string) file_get_contents($this->recipientsPath())) !== '') {
            return true;
        }

        $line = $name !== '' ? $name . ' <' . $email . '>' : $email;
        $content = "# Potts On This Day Email recipients\n"
            . "# One recipient per line. Examples:\n"
            . "# Example Person <person@example.com>\n"
            . "# person@example.com\n"
            . "# person@example.com|Example Person\n"
            . "\n"
            . $line . "\n";

        return file_put_contents($this->recipientsPath(), $content, LOCK_EX) !== false;
    }

    private function senderFromSettings(array $settings, array $fallback_recipient): UserInterface
    {
        $email = (string) ($settings['sender_email'] ?? '');
        $name = (string) ($settings['sender_name'] ?? '');

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $email = $fallback_recipient['email'];
            $name = $fallback_recipient['name'];
        }

        return $this->emailUser($email, $name !== '' ? $name : $email);
    }

    private function effectiveUserRelationshipSettings(Tree $tree, UserInterface $user): array
    {
        $settings = $this->userRelationshipSettings($tree, $user);
        $root_xref = $this->relationshipRootXref($settings, '');

        if ($root_xref === '') {
            $linked_xref = $this->linkedIndividualXref($tree, $user);
            if ($linked_xref !== '') {
                $settings['relationship_filter_enabled'] = $settings['relationship_filter_enabled'] ?? '1';
                $settings['relationship_root_xref'] = $linked_xref;
            } else {
                $settings['relationship_filter_enabled'] = $settings['relationship_filter_enabled'] ?? '0';
                $settings['relationship_root_xref'] = '';
            }
        }

        $settings['personal_preview_enabled'] = '1';
        $settings['daily_email_enabled'] = $settings['daily_email_enabled'] ?? '0';
        $settings['relationship_max_steps'] = $settings['relationship_max_steps'] ?? '4';

        return $settings;
    }

    private function userRelationshipSettings(Tree $tree, UserInterface $user): array
    {
        $all = $this->readUserSettings();
        $tree_key = $tree->name();
        $user_key = (string) $user->id();
        $settings = $all[$tree_key][$user_key] ?? [];

        if (!is_array($settings)) {
            $settings = [];
        }

        return [
            'personal_preview_enabled' => '1',
            'daily_email_enabled' => (string) ($settings['daily_email_enabled'] ?? '0'),
            'relationship_filter_enabled' => (string) ($settings['relationship_filter_enabled'] ?? '1'),
            'relationship_root_xref' => strtoupper(trim((string) ($settings['relationship_root_xref'] ?? ''))),
            'relationship_max_steps' => (string) ($settings['relationship_max_steps'] ?? '4'),
            'email' => (string) ($settings['email'] ?? ''),
            'name' => (string) ($settings['name'] ?? ''),
        ];
    }

    private function saveUserRelationshipSettings(Tree $tree, UserInterface $user, array $settings): bool
    {
        $all = $this->readUserSettings();
        $tree_key = $tree->name();
        $user_key = (string) $user->id();

        if (!isset($all[$tree_key]) || !is_array($all[$tree_key])) {
            $all[$tree_key] = [];
        }

        $all[$tree_key][$user_key] = [
            'personal_preview_enabled' => '1',
            'daily_email_enabled' => (string) ($settings['daily_email_enabled'] ?? '0'),
            'relationship_filter_enabled' => (string) ($settings['relationship_filter_enabled'] ?? '1'),
            'relationship_root_xref' => strtoupper(trim((string) ($settings['relationship_root_xref'] ?? ''))),
            'relationship_max_steps' => (string) ($settings['relationship_max_steps'] ?? '4'),
            'email' => (string) ($settings['email'] ?? ''),
            'name' => (string) ($settings['name'] ?? ''),
            'updated' => $this->localDateTime(),
        ];

        return $this->writeUserSettings($all);
    }

    /**
     * Registered users who have opted in to a personalised daily email.
     *
     * Each item includes the real webtrees user object. The scheduled process uses
     * this to build each email while temporarily logged in as that subscriber,
     * so living/private records are included only when that subscriber is
     * allowed to see them in webtrees.
     *
     * @return array<int, array{user_id:int,user:UserInterface,recipient:array{email:string,name:string},settings:array<string,string>}>
     */
    private function dailyEmailOptIns(Tree $tree): array
    {
        $all = $this->readUserSettings();
        $tree_key = $tree->name();
        $tree_settings = $all[$tree_key] ?? [];
        if (!is_array($tree_settings)) {
            return [];
        }

        $opt_ins = [];
        $seen = [];
        foreach ($tree_settings as $user_id => $settings) {
            if (!is_array($settings)) {
                continue;
            }

            if ((string) ($settings['daily_email_enabled'] ?? '0') !== '1') {
                continue;
            }

            $subscriber_user = $this->userById((int) $user_id);
            if (!$subscriber_user instanceof UserInterface || $subscriber_user->id() <= 0) {
                continue;
            }

            $email = trim($this->plain($subscriber_user->email()));
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $root_xref = strtoupper(trim((string) ($settings['relationship_root_xref'] ?? '')));
            if ($root_xref === '') {
                continue;
            }

            $root = Registry::individualFactory()->make($root_xref, $tree);
            if (!$root instanceof Individual) {
                continue;
            }

            $key = strtolower($email);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $user_settings = [
                'personal_preview_enabled' => '1',
                'daily_email_enabled' => '1',
                'relationship_filter_enabled' => (string) ($settings['relationship_filter_enabled'] ?? '1'),
                'relationship_root_xref' => $root_xref,
                'relationship_max_steps' => (string) ($settings['relationship_max_steps'] ?? '4'),
            ];

            $name = trim($this->plain($subscriber_user->realName()));
            if ($name === '') {
                $name = trim($this->plain($subscriber_user->userName()));
            }

            $opt_ins[] = [
                'user_id' => $subscriber_user->id(),
                'user' => $subscriber_user,
                'recipient' => ['email' => $email, 'name' => $name !== '' ? $name : $email],
                'settings' => $user_settings,
            ];
        }

        return $opt_ins;
    }

    private function userById(int $user_id): ?UserInterface
    {
        if ($user_id <= 0) {
            return null;
        }

        try {
            $user_service = Registry::container()->get(UserService::class);
        } catch (Throwable) {
            $user_service = app(UserService::class);
        }

        if (!$user_service instanceof UserService) {
            return null;
        }

        return $user_service->find($user_id);
    }

    private function runAsUser(UserInterface $user, callable $callback)
    {
        $had_original_user = Session::has('wt_user');
        $original_user_id = Session::get('wt_user');

        Session::put('wt_user', $user->id());

        try {
            return $callback();
        } finally {
            if ($had_original_user) {
                Session::put('wt_user', $original_user_id);
            } else {
                Session::forget('wt_user');
            }
        }
    }


    private function readUserSettings(): array
    {
        $path = $this->userSettingsPath();
        if (!is_file($path)) {
            return [];
        }

        $json = file_get_contents($path);
        $settings = json_decode($json ?: '', true);

        return is_array($settings) ? $settings : [];
    }

    private function writeUserSettings(array $settings): bool
    {
        $dir = dirname($this->userSettingsPath());
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $this->ensureHtaccess();

        $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return file_put_contents($this->userSettingsPath(), $json . PHP_EOL, LOCK_EX) !== false;
    }

    private function linkedIndividualXref(Tree $tree, UserInterface $user): string
    {
        if ($user->id() <= 0) {
            return '';
        }

        try {
            $xref = (string) $tree->getUserPreference($user, UserInterface::PREF_TREE_ACCOUNT_XREF);
        } catch (Throwable) {
            $xref = '';
        }

        $xref = strtoupper(trim($xref));
        if ($xref === '') {
            return '';
        }

        return Registry::individualFactory()->make($xref, $tree) instanceof Individual ? $xref : '';
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR;
    }

    private function assertAdministrator(ServerRequestInterface $request): void
    {
        $user = Validator::attributes($request)->user();

        if (!Auth::isAdmin($user)) {
            throw new HttpAccessDeniedException();
        }
    }

    /**
     * @return array<int,Tree>
     */
    private function availableTrees(): array
    {
        try {
            /** @var TreeService $tree_service */
            $tree_service = Registry::container()->get(TreeService::class);
            $trees = [];

            foreach ($tree_service->all() as $tree) {
                if ($tree instanceof Tree) {
                    $trees[] = $tree;
                }
            }

            usort($trees, static fn (Tree $a, Tree $b): int => strcasecmp($a->title(), $b->title()));

            return $trees;
        } catch (Throwable) {
            return [];
        }
    }

    private function treeFromName(string $tree_name): ?Tree
    {
        if ($tree_name === '') {
            return null;
        }

        foreach ($this->availableTrees() as $tree) {
            if ($tree->name() === $tree_name) {
                return $tree;
            }
        }

        return null;
    }

    private function requestedTreeName(ServerRequestInterface $request): string
    {
        $tree_name = Validator::queryParams($request)->string('tree', '');
        if ($tree_name !== '') {
            return $tree_name;
        }

        $tree = $request->getAttribute('tree');
        if ($tree instanceof Tree) {
            return $tree->name();
        }
        if (is_string($tree)) {
            return $tree;
        }

        return '';
    }

    private function moduleAdminUrl(?Tree $tree = null, array $parameters = [], string $return_url = ''): string
    {
        $route_parameters = [];

        if ($tree instanceof Tree) {
            $route_parameters['tree'] = $tree->name();
        }

        $return_url = $this->cleanReturnUrl($return_url);
        if ($return_url !== '') {
            $route_parameters['return_url'] = $return_url;
        }

        foreach ($parameters as $key => $value) {
            $route_parameters[(string) $key] = (string) $value;
        }

        return $this->urlWithQuery(route('module', [
            'module' => $this->name(),
            'action' => 'Admin',
        ]), $route_parameters);
    }

    private function urlWithQuery(string $url, array $parameters): string
    {
        if ($parameters === []) {
            return $url;
        }

        $fragment = '';
        if (str_contains($url, '#')) {
            [$url, $fragment] = explode('#', $url, 2);
            $fragment = '#' . $fragment;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($parameters) . $fragment;
    }

    private function controlPanelUrl(): string
    {
        try {
            return route('admin-control-panel');
        } catch (Throwable) {
            $request_uri = $_SERVER['REQUEST_URI'] ?? '';
            $parts = parse_url($request_uri);
            $path = $parts['path'] ?? '';
            $base = '';

            if (($index = strpos($path, '/index.php')) !== false) {
                $base = substr($path, 0, $index);
            }

            return ($base !== '' ? $base : '') . '/admin';
        }
    }

    private function cleanReturnUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || str_contains($url, "\r") || str_contains($url, "\n") || str_starts_with($url, '//')) {
            return '';
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }

        if (isset($parts['scheme']) || isset($parts['host'])) {
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower((string) ($parts['host'] ?? ''));
            $current_host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

            if (!in_array($scheme, ['http', 'https'], true) || $host === '' || $host !== $current_host) {
                return '';
            }
        }

        $path = (string) ($parts['path'] ?? '');
        if (!str_starts_with($path, '/')) {
            return '';
        }

        $return = $path;
        if (isset($parts['query'])) {
            $return .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $return .= '#' . $parts['fragment'];
        }

        return $return;
    }

    private function adminRedirect(string $tree_name, array $parameters = [], string $return_url = ''): ResponseInterface
    {
        $route_parameters = [];

        if ($tree_name !== '') {
            $route_parameters['tree'] = $tree_name;
        }

        $return_url = $this->cleanReturnUrl($return_url);
        if ($return_url !== '') {
            $route_parameters['return_url'] = $return_url;
        }

        foreach ($parameters as $key => $value) {
            $route_parameters[(string) $key] = (string) $value;
        }

        return redirect($this->urlWithQuery(route('module', [
            'module' => $this->name(),
            'action' => 'Admin',
        ]), $route_parameters));
    }

    private function settings(): array
    {
        $path = $this->settingsPath();
        if (!is_file($path)) {
            return [
                'token' => '',
                'sender_email' => '',
                'sender_name' => '',
                'tree' => '',
                'last_run' => '',
                'last_scheduler_attempt' => '',
                'last_result' => '',
                'last_count' => '0',
                'last_recipients' => '',
                'last_error' => '',
                'relationship_filter_enabled' => '1',
                'relationship_root_xref' => '',
                'relationship_max_steps' => '4',
            ];
        }

        $json = file_get_contents($path);
        $settings = json_decode($json ?: '', true);

        return is_array($settings) ? $settings : [];
    }

    private function saveSettings(array $settings): bool
    {
        $dir = dirname($this->settingsPath());
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $this->ensureHtaccess();

        $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return file_put_contents($this->settingsPath(), $json . PHP_EOL, LOCK_EX) !== false;
    }


    private function localTimezoneName(): string
    {
        $settings = $this->settings();
        $timezone = (string) ($settings['timezone'] ?? self::DEFAULT_TIMEZONE);

        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            return self::DEFAULT_TIMEZONE;
        }

        return $timezone;
    }

    private function localNow(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone($this->localTimezoneName()));
    }

    private function localDateKey(): string
    {
        return $this->localNow()->format('Y-m-d');
    }

    private function localDateHeading(): string
    {
        return $this->localNow()->format('j F');
    }

    private function localDateTime(): string
    {
        return $this->localNow()->format('Y-m-d H:i:s T');
    }

    private function ensureHtaccess(): void
    {
        $path = __DIR__ . '/data/.htaccess';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            return;
        }
        if (!is_file($path)) {
            @file_put_contents($path, "Require all denied\n", LOCK_EX);
        }
    }

    private function schedulerLog(string $message): void
    {
        $dir = __DIR__ . '/data';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return;
        }

        $this->ensureHtaccess();

        $line = '[' . $this->localDateTime() . '] ' . $message . PHP_EOL;
        @file_put_contents($dir . '/scheduler.log', $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * @return resource|null
     */
    private function acquireRunLock()
    {
        $dir = __DIR__ . '/data';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return null;
        }

        $handle = @fopen($dir . '/run.lock', 'c');
        if ($handle === false || !flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            return null;
        }

        return $handle;
    }

    /**
     * @param resource $handle
     */
    private function releaseRunLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private function settingsPath(): string
    {
        return __DIR__ . '/data/settings.json';
    }

    private function recipientsPath(): string
    {
        return __DIR__ . '/data/recipients.txt';
    }

    private function userSettingsPath(): string
    {
        return __DIR__ . '/data/user_settings.json';
    }

    private function sendUrl(int $block_id, string $mode = 'me'): string
    {
        return $this->currentPageUrl(['potts_otd_send' => $mode, 'potts_otd_block_id' => (string) $block_id]);
    }

    private function currentPageUrl(array $add): string
    {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $parts = parse_url($request_uri);
        $path = $parts['path'] ?? $request_uri;
        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        foreach (array_keys($query) as $key) {
            if (str_starts_with((string) $key, 'potts_otd_')) {
                unset($query[$key]);
            }
        }

        foreach ($add as $key => $value) {
            $query[$key] = $value;
        }

        if ($query === []) {
            return $path;
        }

        return $path . '?' . http_build_query($query);
    }

    private function currentPagePath(): string
    {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $parts = parse_url($request_uri);

        return $parts['path'] ?? $request_uri;
    }

    private function currentQueryHiddenFields(array $add): string
    {
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        $parts = parse_url($request_uri);
        $query = [];
        if (isset($parts['query'])) {
            parse_str($parts['query'], $query);
        }

        foreach (array_keys($query) as $key) {
            if (str_starts_with((string) $key, 'potts_otd_')) {
                unset($query[$key]);
            }
        }

        foreach ($add as $key => $value) {
            $query[$key] = $value;
        }

        $html = '';
        foreach ($query as $key => $value) {
            if (is_array($value)) {
                continue;
            }
            $html .= '<input type="hidden" name="' . $this->esc((string) $key) . '" value="' . $this->esc((string) $value) . '">';
        }

        return $html;
    }

    private function runDailyUrl(Tree $tree, string $token): string
    {
        $base = route('module', [
            'module' => $this->name(),
            'action' => 'RunDaily',
            'tree'   => $tree->name(),
        ]);

        $separator = str_contains($base, '?') ? '&' : '?';

        return $this->absoluteUrl($base . $separator . 'token=' . urlencode($token));
    }

    private function absoluteUrl(string $url): string
    {
        if (parse_url($url, PHP_URL_SCHEME) !== null) {
            return $url;
        }

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

        if (!str_starts_with($url, '/')) {
            $url = '/' . $url;
        }

        return $scheme . '://' . $host . $url;
    }

    private function emailUser(string $email, string $name): UserInterface
    {
        return new class($email, $name) implements UserInterface {
            public function __construct(private string $email, private string $name)
            {
            }

            public function id(): int
            {
                return 0;
            }

            public function email(): string
            {
                return $this->email;
            }

            public function realName(): string
            {
                return $this->name;
            }

            public function userName(): string
            {
                return $this->email;
            }

            public function getPreference(string $setting_name, string $default = ''): string
            {
                return $default;
            }

            public function setPreference(string $setting_name, string $setting_value): void
            {
            }
        };
    }

    private function textResponse(string $message, int $status = 200): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'text/plain; charset=UTF-8'], $message . PHP_EOL);
    }

    private function plain(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
};
