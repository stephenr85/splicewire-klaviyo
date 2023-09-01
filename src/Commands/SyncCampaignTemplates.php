<?php

namespace SpearGen\Klaviyo\Commands;

use Illuminate\Console\Command;
use KlaviyoAPI\KlaviyoAPI;
use League\HTMLToMarkdown\HtmlConverter;
use SpearGen\Client\Requests\Fragments\CreateFragment;
use Symfony\Component\DomCrawler\Crawler;

class SyncCampaignTemplates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'klaviyo:sync-campaign-templates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync all messages from configured accounts and campaign templates.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $spearGen = new \SpearGen\Client\Connector(config('services.speargen.url'), config('services.speargen.user'), config('services.speargen.password'));

        // Get Klaviyo accounts
        foreach(config('speargen.klaviyo.accounts') as $key => $account) {
            $this->info(str_repeat('=', 20) . " Sync campaign messages from account: $key " . str_repeat('=', 20));
            $klaviyo = new KlaviyoAPI(data_get($account, 'api_key'));
            $silos = data_get($account, 'campaigns.fragment_silos');
            $filter = data_get($account, 'campaigns.filter');
            $campaignsPagingStarted = false;
            $campaignsNextPageCursor = null;
            $templatesSynced = [];

            while(!$campaignsPagingStarted || $campaignsNextPageCursor) {
                $campaignsPagingStarted = true;
                $campaignsResponse = $klaviyo->Campaigns->getCampaigns($filter, [], null, [], ['tags'], $campaignsNextPageCursor, '-scheduled_at');
                $campaignsNextPageCursor = data_get($campaignsResponse, 'links.next');
                if($campaignsNextPageCursor) $this->info($campaignsNextPageCursor);
                $campaignsData = data_get($campaignsResponse, 'data');
                $tags = [
                    'klaviyo',
                    'klaviyo template',
                ];
                foreach($campaignsData as $campaignData) {
                    $this->info('Processing campaign: ' . data_get($campaignData, 'attributes.name'));
                    //$messagesData = collect(data_get($campaignsResponse, 'included'))->where('type', 'campaign-message');
                    // https://github.com/klaviyo/klaviyo-api-php/tree/main#get-campaign-campaign-messages
                    $messagesResponse = $klaviyo->Campaigns->getCampaignCampaignMessages(data_get($campaignData, 'id'), null, null, ['name', 'editor_type', 'text','html', 'updated'], ['template']);
                    $messagesData = data_get($messagesResponse, 'data');

                    $templates = collect(data_get($messagesResponse, 'included'))->where('type', 'template');
                    foreach($messagesData as $messageData) {

                        try {

                            //$messageResponse = $klaviyo->Campaigns->getCampaignMessage(data_get($messageData, 'id'), null, null, ['name', 'editor_type', 'text','html', 'updated'], ['template']);
                            //$messageData = data_get($messageResponse, 'data');

                            //$templateData = collect(data_get($messageResponse, 'included'))->where('type', 'template')->first();
                            //$templateId = data_get($templateData, 'id');

                            $templateId = data_get($messageData, 'relationships.template.data.id');
                            $templateData = $templates->firstWhere('id', $templateId);

                            if(in_array($templateId, $templatesSynced)) continue; // skip if already synced
                            $templatesSynced[] = $templateId;

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
                                'meta' => [
                                    'source' => 'klaviyo',
                                    'source_type' => 'template',
                                    'source_id' => $templateId,
                                ],
                            ];

                            $request = new CreateFragment();
                            $request->body()->merge($fragmentData);
                            $response = $spearGen->send($request);
                            $json = $response->json();
                            if(data_get($json, 'status') == 'error') {
                                throw new \Exception(data_get($json, 'message'));
                            }
                        } catch(\Throwable $e) {
                            $this->error($e);
                        }
                    }
                }
            }
        }

    }
}
