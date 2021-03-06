<?php

namespace Shoplo\AllegroBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand as Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Shoplo\AllegroBundle\WebAPI\Shoplo;
use HWI\Bundle\OAuthBundle\Security\Core\Authentication\Token\OAuthToken;
use Shoplo\AllegroBundle\Entity\User;
use Shoplo\AllegroBundle\Entity\Deal;
use Shoplo\AllegroBundle\Entity\ShoploOrder;
use Doctrine\ORM\EntityNotFoundException;
use Shoplo\AllegroBundle\WebAPI\Allegro;
use Shoplo\AllegroBundle\Entity\Item;
use Shoplo\AllegroBundle\Entity\SellAgainItem;
use Symfony\Component\Validator\Constraints\DateTime;

class ImportCommand extends Command
{
    protected function configure()
    {
        $this
            ->setName('allegro:import')
            ->setDescription('Importowanie zamówień z Allegro do Shoplo');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var $doctrine \Doctrine\Bundle\DoctrineBundle\Registry */
        $doctrine = $this->getContainer()->get('doctrine');

        /** @var $repository \Shoplo\AllegroBundle\Entity\UserRepository */
        $repository = $doctrine->getRepository('ShoploAllegroBundle:User');

		$dealRepository = $doctrine->getRepository('ShoploAllegroBundle:Deal');

		$manager = $doctrine->getManager();

        /** @var $users User[] */
        $users = $repository->findAll();

        /** @var $allegro Allegro */
        $allegro = $this->getContainer()->get('allegro');

        foreach ($users as $user) {
			if ( !$user->getUsername() || $disable = $user->getDisable() ) {
				continue;
			}

            if (!$allegro->login($user)) {
                $output->writeln('<error>Unable to log-in to Allegro</error>');
                continue;
            }

            try
            {
                $sellAgainItems = $doctrine
                    ->getRepository('ShoploAllegroBundle:SellAgainItem')
                    ->findBy(
                        array('user_id' => $user->getId())
                    );

                foreach($sellAgainItems as $sellAgainItem)
                {
					$result = $allegro->doVerifyItem($allegro->getSession(), $sellAgainItem->getLocalId());
					if($result['item-listed'] == 1)
                    {
                        $item = $doctrine
                            ->getRepository('ShoploAllegroBundle:Item')
                            ->findOneBy(
                                array('id' => $sellAgainItem->getItemId(), 'user_id' => $user->getId())
                            );

                        $newItem = new Item();
                        $endTime = strtotime('+' . $sellAgainItem->getDuration() . ' days', $result['item-starting-time']);
                        $newItem
                            ->setId($result['item-id'])
                            ->setUser($user)
                            ->setVariantId($item->getVariantId())
                            ->setProductId($item->getProductId())
                            ->setPrice($item->getPrice())
                            ->setQuantity($item->getQuantity())
                            ->setQuantityAll($item->getQuantityAll())
                            ->setQuantitySold(0)
                            ->setViewsCount(0)
                            ->setWatchCount(0)
                            ->setAuctionPrice($item->getAuctionPrice())
                            ->setStartAt(new \DateTime(date('Y-m-d H:i:s', $result['item-starting-time'])))
                            ->setEndAt(new \DateTime(date('Y-m-d H:i:s', $endTime)));

                        $manager->remove($sellAgainItem);
                        $manager->persist($newItem);
                    }
                }
            }
            catch(Exception $e)
            {
                $output->writeln('<error>Sell again items error: '.$e->getMessage().'</error>');
                continue;
            }



            $auctionsIds = $newTransactionAuctionMap = array();
            $deals       = $allegro->getDeals($user->getLastEventId(), $manager, $dealRepository);

            if (empty($deals)) {
                $output->writeln('<comment>No deals found</comment>');
                continue;
            }

            $output->writeln('<info>Found ' . count($deals) . ' deals for user: ' . $user->getUsername() . '['.$user->getShopId().']</info>');

            foreach ($deals as $deal) {

				$auctionsIds[] = $deal->getItemId();

                /**
                 * Każde z typów zdarzeń (oprócz 1, dla którego ID transakcji nie jest jeszcze znane),
                 * generowane jest osobno dla każdego deala wchodzącego w skład transakcji.
                 */
                switch ($deal->getEventType()) {
                    case 1: # utworzenie aktu zakupowego (deala)
						$output->writeln('<info>Utworzenie aktu zakupowego. ItemId: '.$deal->getItemId().'</info>');
                        continue;
                    case 2: # utworzenie formularza pozakupowego (transakcji)
						$output->writeln('<info>Utworzenie formularza pozakupowego. ItemId: '.$deal->getItemId().'</info>');
                        $newTransactionAuctionMap[$deal->getTransactionId()] = $deal->getItemId();
                        break;
                    case 3: # anulowanie formularza pozakupowego (transakcji)
						$output->writeln('<info>Anulowanie formularza pozakupowego. ItemId: '.$deal->getItemId().'</info>');
                        // TODO: cancel order in Shoplo
                        break;
                    case 4: # zakończenie (opłacenie) transakcji przez PzA)
						$output->writeln('<info>Zakonczenie (oplacenie) transakcji. ItemId: '.$deal->getItemId().'</info>');
                        // TODO: mark order as paid
                        break;
                }
            }

			$auctionsIds = array_unique($auctionsIds);
			$postBuyData = !empty($auctionsIds) ? $allegro->getPostBuyData($auctionsIds) : array();

			$auctionIdsTrans = array();
            if ( !empty($newTransactionAuctionMap) && !empty($auctionsIds) )
			{
                $buyersFormsData = $allegro->getBuyersData(array_keys($newTransactionAuctionMap));
				foreach ($buyersFormsData as $data)
				{
					$data = (array) $data;
					$auctionId = $newTransactionAuctionMap[$data['post-buy-form-id']];

					if ( isset($auctionIdsTrans[$auctionId]) )
					{
						continue;
					}
					else
					{
						$auctionIdsTrans[$auctionId] = 1;
					}

					$items = array();
					foreach ( $data['post-buy-form-items'] as $d )
					{
						$d = (array) $d;
						$item = $doctrine->getRepository('ShoploAllegroBundle:Item')->findOneById($d['post-buy-form-it-id']);
						if ( $item instanceof Item )
						{
							$item->setQuantitySold($item->getQuantitySold()+$d['post-buy-form-it-quantity']);
							$items[$d['post-buy-form-it-id']] = $item;
						}
					}

                    //$item = $doctrine->getRepository('ShoploAllegroBundle:Item')->findOneById($auctionId);
					if ( !empty($items) )
					{
						$buyerId   = $data['post-buy-form-buyer-id'];
						$buyer     = array();
						foreach ($postBuyData as $d) {
							if ($d['item_id'] == $auctionId) {
								$buyer = $d['buyers'][$buyerId];
								break;
							}
						}

						$shoplo = $this->getShop($user);
						$order  = $this->createShoploOrder($items, $data, $user, $buyer, $allegro, $shoplo, $output);


					}
                }
            }

            // Zapamiętanie ostatniego zdarzenia
            $lastDeal = array_pop($deals);
            $user->setLastEventId($lastDeal->getId());
            $doctrine->getManager()->flush();
        }
    }

    /**
     * @param  User                    $user
     * @throws EntityNotFoundException
     * @return Shoplo
     */
    private function getShop(User $user)
    {
        $token    = new OAuthToken(array(
            'oauth_token'        => $user->getOauthToken(),
            'oauth_token_secret' => $user->getOauthTokenSecret()
        ));
        $security = $this->getContainer()->get('security.context');
        $security->setToken($token);

        $key    = $this->getContainer()->getParameter('oauth_consumer_key');
        $secret = $this->getContainer()->getParameter('oauth_consumer_secret');

        return new Shoplo($key, $secret, $security);
    }

    /**
     * Tworzy zamowienie w Shoplo
     *
     * @param $auction
     * @param $auctionData
     * @param $user
     * @param $buyer
     * @param  Allegro $allegro
     * @param  Shoplo  $shoplo
     * @return array
     */
    public function createShoploOrder($items, $auctionData, $user, $buyer, Allegro $allegro, Shoplo $shoplo, OutputInterface $output)
    {
		$shippingAddress = (array) $auctionData['post-buy-form-shipment-address'];
        list($shippingFirstName, $shippingLastName) = explode(' ', $shippingAddress['post-buy-form-adr-full-name'], 2);

        $paymentMethods  = Allegro::getPaymentMethods();
        $shippingMethods = $allegro->getShippingMethods();


		$allegroCountriesKeys = array();
		$allegroCountries = $allegro->doGetCountries($shippingAddress['post-buy-form-adr-country'], $allegro->getKey());
		foreach ( $allegroCountries as $ac )
		{
			$ac = (array) $ac;
			$allegroCountriesKeys[$ac['country-id']] = $ac['country-name'];
		}
		$allegroCountriesMap = $allegro->getCountryMap();
		$allegroCountriesMap = array_flip($allegroCountriesMap);

        if( isset($shippingAddress['post-buy-form-adr-company']) && strlen($shippingAddress['post-buy-form-adr-company']) > 0 )
        {
            $shippingFirstName = $shippingAddress['post-buy-form-adr-company'];
            $shippingLastName  = $shippingAddress['post-buy-form-adr-full-name'];
        }

		$refs = $items;
		$ref = /*(count($items) == 1) ? array_pop($refs) : */null;

		$order = array(
            'shipping_details' => array(
                'title' => isset($shippingMethods[$auctionData['post-buy-form-shipment-id']]) ? $shippingMethods[$auctionData['post-buy-form-shipment-id']]['shipment-name'] : 'Nie określony',
                'price' => bcmul($auctionData['post-buy-form-postage-amount'], 100),
            ),
            'payment_details'  => array(
                'title' => isset($paymentMethods[$auctionData['post-buy-form-pay-type']]) ? $paymentMethods[$auctionData['post-buy-form-pay-type']] : 'Nie określony',
            ),
            'customer'         => array(
                'first_name'        => $buyer['user-data']['user-first-name'],
                'last_name'         => $buyer['user-data']['user-last-name'],
                'email'             => $buyer['user-data']['user-email'],
                'phone'             => $buyer['user-data']['user-phone'],
                'accept_newsletter' => '0',
                'address'           => array(
                    'street'       => $shippingAddress['post-buy-form-adr-street'],
                    'city'         => $shippingAddress['post-buy-form-adr-city'],
                    'zip_code'     => $shippingAddress['post-buy-form-adr-postcode'],
                    'country_code' => $allegroCountriesMap[$allegroCountriesKeys[$shippingAddress['post-buy-form-adr-country']]],
                ),
            ),
            'shipping_address' => array(
                'first_name'   => $shippingFirstName,
                'last_name'    => $shippingLastName,
                'street'       => $shippingAddress['post-buy-form-adr-street'],
                'phone'        => $shippingAddress['post-buy-form-adr-phone'],
                'city'         => $shippingAddress['post-buy-form-adr-city'],
                'zip_code'     => $shippingAddress['post-buy-form-adr-postcode'],
                'country_code' => $allegroCountriesMap[$allegroCountriesKeys[$shippingAddress['post-buy-form-adr-country']]],
            ),
            /*'order_items'      => array(
                array(
                    'variant_id' => $item->getVariantId(),
                    'quantity'   => $auctionData['post-buy-form-items']['post-buy-form-it-quantity'],
                    'price'      => $auctionData['post-buy-form-items']['post-buy-form-it-price'],
                ),
            ),*/
            'referring_site'   => !is_null($ref) ? 'http://allegro.pl/i'.$ref->getId().'.html' : 'http://allegro.pl',
            'landing_site'     => '/',
            'notes'            => $auctionData['post-buy-form-msg-to-seller'],
        );

		$price = 0;
		$formItems = (array) $auctionData['post-buy-form-items'];
		foreach ( $formItems as $it )
		{
			$it = (array) $it;
			if ( isset($items[$it['post-buy-form-it-id']]) )
			{
				$item = $items[$it['post-buy-form-it-id']];
				$order['order_items'][] = array(
					'variant_id' => $item->getVariantId(),
					'quantity'   => $it['post-buy-form-it-quantity'],
					'price'      => bcmul($it['post-buy-form-it-price'], 100, 2),
				);
				$price += bcmul($it['post-buy-form-it-price'], 100, 2);
			}
		}

        if ($auctionData['post-buy-form-invoice-option']) {
			$invoiceData = (array) $auctionData['post-buy-form-invoice-data'];
            list($firstName, $lastName) = explode(
                ' ',
				$invoiceData['post-buy-form-adr-full-name'],
                2
            );
            $order['billing_address'] = array(
                'company'      => $invoiceData['post-buy-form-adr-company'],
                'first_name'   => $firstName,
                'last_name'    => $lastName,
                'street'       => $invoiceData['post-buy-form-adr-street'],
                'city'         => $invoiceData['post-buy-form-adr-city'],
                'zip_code'     => $invoiceData['post-buy-form-adr-postcode'],
                'country_code' => $allegroCountriesMap[$allegroCountriesKeys[$invoiceData['post-buy-form-adr-country']]],
                'tax_id'       => $invoiceData['post-buy-form-adr-nip'],
            );
        }

		$output->writeln('<info>Order: '.print_r($order, true).'</info>');

		$result = $shoplo->post('orders', array('order' => $order));

		if ( !empty($result) && isset($result['order']) )
		{
			$orderObj = new ShoploOrder();
			$orderObj->setOrderId($result['order']['id']);
			$orderObj->setUserId($user->getId());
			$orderObj->setVariantId($item->getVariantId());
			$orderObj->setProductId($item->getProductId());
			$orderObj->setPrice((int) $price);
			$orderObj->setShippingTitle($order['shipping_details']['title']);
			$orderObj->setShippingPrice((int) $order['shipping_details']['price']);
			$orderObj->setPaymentTitle($order['payment_details']['title']);
			$orderObj->setReferringSite($order['referring_site']);
			$orderObj->setLandingSite($order['landing_site']);
			$orderObj->setNotes($order['notes']);
			$orderObj->setCustomerEmail($buyer['user-data']['user-email']);
			$orderObj->setCustomerPhone($buyer['user-data']['user-phone']);
			$orderObj->setShippingName($shippingFirstName.' '.$shippingLastName);
			$orderObj->setShippingAddress1($order['shipping_address']['street']);
			$orderObj->setShippingCity($order['shipping_address']['city']);
			$orderObj->setShippingZipCode($order['shipping_address']['zip_code']);
			$orderObj->setShippingCountryCode($order['shipping_address']['country_code']);
			$orderObj->setShippingPhone($order['shipping_address']['phone']);
			$orderObj->setBillingName(isset($order['billing_address']['company']) ? $order['billing_address']['company'] : $shippingFirstName.' '.$shippingLastName);
			$orderObj->setBillingAddress1($order['billing_address']['street']);
			$orderObj->setBillingCity($order['billing_address']['city']);
			$orderObj->setBillingZipCode($order['billing_address']['zip_code']);
			$orderObj->setBillingCountryCode($order['billing_address']['country_code']);
			$orderObj->setBillingTaxId($order['billing_address']['tax_id']);
			$orderObj->setCreatedAt(new \DateTime());

			$em = $this->getContainer()->get('doctrine')->getManager();
			$em->persist($orderObj);
			$em->flush();
		}

		return $result;
    }
}
