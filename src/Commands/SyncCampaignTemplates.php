<?php

namespace Splicewire\Klaviyo\Commands;

use App\Models\SyncRef;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use KlaviyoAPI\KlaviyoAPI;
use League\HTMLToMarkdown\HtmlConverter;
use Splicewire\Client\Requests\Fragments\CreateFragment;
use Splicewire\Client\SplicewireConnector;
use Symfony\Component\DomCrawler\Crawler;

class SyncCampaignTemplates extends Command
{
    public KlaviyoAPI $klaviyo;
    public $accountConfig;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'klaviyo:sync-campaign-templates {--accounts=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync all messages from configured accounts and campaign templates.';

    public function __construct(public SplicewireConnector $splicewire)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $syncAccounts = explode(',', $this->option('accounts') ?: '');
        // Get Klaviyo accounts
        foreach(config('splicewire.klaviyo.accounts') as $key => $account) {
            if($syncAccounts && !in_array($key, $syncAccounts)) continue;

            $filter = data_get($account, 'campaigns.filter');
            $filter = "and($filter,)";

            $this->syncAccount($account);
        }

    }

    public function syncAccount($accountConfig) {
        $this->accountConfig = $accountConfig;
        $this->info(str_repeat('=', 20) . " Sync campaign messages from account: " . str_repeat('=', 20));
        $this->klaviyo = new KlaviyoAPI(data_get($this->accountConfig, 'api_key'));

        $filter = data_get($this->accountConfig, 'campaigns.filter');
        $campaignsPagingStarted = false;
        $campaignsNextPageCursor = null;

        while(!$campaignsPagingStarted || $campaignsNextPageCursor) {
            $campaignsPagingStarted = true;
            $campaignsResponse = $this->klaviyo->Campaigns->getCampaigns($filter, [], null, [], ['tags'], $campaignsNextPageCursor, '-scheduled_at');
            $campaignsNextPageCursor = data_get($campaignsResponse, 'links.next');
            if($campaignsNextPageCursor) $this->info($campaignsNextPageCursor);
            $campaignsData = data_get($campaignsResponse, 'data');

            $silos = data_get($this->accountConfig, 'campaigns.fragment_silos');

            $tags = [
                'klaviyo',
                'klaviyo template',
            ];

            foreach($campaignsData as $campaignData) {
                $this->syncCampaign($campaignData, $silos, $tags);
            }
        }
    }

    public function syncCampaign(array $campaignData, array $silos, array $tags) {

        try {
            $campaignSyncRef = SyncRef::firstOrNew([
                'source' => 'klaviyo',
                'source_type' => 'campaign',
                'source_id' => data_get($campaignData, 'id'),
            ]);

            if($campaignSyncRef->status == SyncRef::STATUS_SUCCESS && $campaignSyncRef->synced_at >= strtotime(data_get($campaignData, 'attributes.updated_at'))) {
                // $this->info("\tAlready synced.");
                return;
            }
            $this->info('Processing campaign: ' . data_get($campaignData, 'attributes.name'));

            $campaignSyncRef->synced_at = now();
            //$messagesData = collect(data_get($campaignsResponse, 'included'))->where('type', 'campaign-message');
            // https://github.com/klaviyo/klaviyo-api-php/tree/main#get-campaign-campaign-messages
            $messagesResponse = $this->klaviyo->Campaigns->getCampaignCampaignMessages(data_get($campaignData, 'id'), null, null, ['name', 'editor_type', 'text','html', 'updated'], ['template']);
            $messagesData = data_get($messagesResponse, 'data');

            $templates = collect(data_get($messagesResponse, 'included'))->where('type', 'template');
            foreach($messagesData as $messageData) {

                //$messageResponse = $this->klaviyo->Campaigns->getCampaignMessage(data_get($messageData, 'id'), null, null, ['name', 'editor_type', 'text','html', 'updated'], ['template']);
                //$messageData = data_get($messageResponse, 'data');

                //$templateData = collect(data_get($messageResponse, 'included'))->where('type', 'template')->first();
                //$templateId = data_get($templateData, 'id');

                $templateId = data_get($messageData, 'relationships.template.data.id');
                $templateData = $templates->firstWhere('id', $templateId);

                $templateSyncRef = SyncRef::firstOrNew([
                    'source' => 'klaviyo',
                    'source_type' => 'template',
                    'source_id' => $templateId,
                ]);
                $templateSyncRef->synced_at = now();

                if($templateSyncRef->status == SyncRef::STATUS_SUCCESS && $templateSyncRef->synced_at >= strtotime(data_get($templateData, 'attributes.updated'))) {
                    // $this->info("\t\tAlready synced.");
                    continue;
                }

                $this->info("\tSyncing template " . data_get($messageData, 'attributes.label') . ' : ' . data_get($templateData, 'attributes.name'));

                $text = null;

                // Convert HTML to markdown
                $html = data_get($templateData, 'attributes.html');

                if($html) {
                    $crawler = new Crawler($html);
                    $html = $crawler->filter('body')->html() ?: $crawler->html();
                    $htmlConverter = new HtmlConverter([
                        'strip_tags' => true,
                    ]);
                    $text = $htmlConverter->convert($html);

                }

                // Fallback to template.text property
                if(!$text) {
                    $text = data_get($templateData, 'attributes.text');
                }

                $text = str($text)->trim()->replaceMatches('/\n{3,}/', "\n\n");

                $fragmentData = [
                    'silos' => $silos,
                    'tags' => $tags,
                    'name' => data_get($messageData, 'attributes.label'),
                    'text' => (string)$text,
                    'meta' => $templateSyncRef->only(['source', 'source_type', 'source_id']),
                ];

                $request = new CreateFragment();
                $request->body()->merge($fragmentData);
                $response = $this->splicewire->send($request);
                $json = $response->json();

                if(data_get($json, 'status') == 'error') {
                    $templateSyncRef->status = SyncRef::STATUS_FAIL;
                    $templateSyncRef->save();
                    throw new \Exception(data_get($json, 'message'));
                }

                $templateSyncRef->fragment_id = data_get($json, 'data.id');
                $templateSyncRef->status = SyncRef::STATUS_SUCCESS;
                $templateSyncRef->save();

            }

            $campaignSyncRef->status = SyncRef::STATUS_SUCCESS;

        } catch(\Throwable $e) {
            $this->error($e);
            $campaignSyncRef->status = SyncRef::STATUS_FAIL;
        }

        $campaignSyncRef->save();
    }
}
