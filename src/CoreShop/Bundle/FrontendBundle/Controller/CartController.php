<?php
/**
 * CoreShop.
 *
 * This source file is subject to the GNU General Public License version 3 (GPLv3)
 * For the full copyright and license information, please view the LICENSE.md and gpl-3.0.txt
 * files that are distributed with this source code.
 *
 * @copyright  Copyright (c) 2015-2017 Dominik Pfaffenbauer (https://www.pfaffenbauer.at)
 * @license    https://www.coreshop.org/license     GNU General Public License version 3 (GPLv3)
 */

namespace CoreShop\Bundle\FrontendBundle\Controller;

use CoreShop\Component\Inventory\Model\StockableInterface;
use CoreShop\Component\Order\Cart\Rule\CartPriceRuleProcessorInterface;
use CoreShop\Component\Order\Cart\Rule\CartPriceRuleUnProcessorInterface;
use CoreShop\Component\Order\Manager\CartManagerInterface;
use CoreShop\Component\Order\Model\CartItemInterface;
use CoreShop\Component\Order\Model\CartPriceRuleVoucherCodeInterface;
use CoreShop\Component\Order\Model\PurchasableInterface;
use CoreShop\Component\Order\Repository\CartPriceRuleVoucherRepositoryInterface;
use CoreShop\Component\StorageList\StorageListModifierInterface;
use Pimcore\Model\Object;
use Symfony\Component\HttpFoundation\Request;

class CartController extends FrontendController
{
    /**
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function widgetAction(Request $request)
    {
        return $this->renderTemplate('CoreShopFrontendBundle:Cart:_widget.html.twig', [
            'cart' => $this->getCart(),
        ]);
    }

    /**
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function widgetSummaryAction(Request $request)
    {
        return $this->renderTemplate('CoreShopFrontendBundle:Cart:_widgetSummary.html.twig', [
            'cart' => $this->getCart(),
            'editAllowed' => true
        ]);
    }

    /**
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function addItemAction(Request $request)
    {
        $product = Object::getById($request->get('product'));

        if (!$product instanceof PurchasableInterface) {
            return $this->redirectToRoute('coreshop_index');
        }

        $quantity = intval($request->get('quantity', 1));

        if (!is_int($quantity)) {
            $quantity = 1;
        }

        if ($product instanceof StockableInterface) {
            $hasStock = $this->get('coreshop.inventory.availability_checker.default')->isStockSufficient($product, $quantity);

            if (!$hasStock) {
                $this->addFlash('error', 'coreshop.ui.item_is_out_of_stock');
                return $this->redirectToRoute('coreshop_cart_summary');
            }
        }

        //if cart is only in session, store it before interact with it.
        if($this->getCart()->getId() === 0) {
            $this->getCartManager()->persistCart($this->getCart());
        }

        $this->getCartModifier()->addItem($this->getCart(), $product, $quantity);
        $this->getCartManager()->persistCart($this->getCart());

        $this->addFlash('success', 'coreshop.ui.item_added');

        return $this->redirectToRoute('coreshop_cart_summary');
    }

    /**
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function removeItemAction(Request $request)
    {
        $cartItem = $this->get('coreshop.repository.cart_item')->find($request->get('cartItem'));

        if (!$cartItem instanceof CartItemInterface) {
            return $this->redirectToRoute('coreshop_index');
        }

        if ($cartItem->getCart()->getId() !== $this->getCart()->getId()) {
            return $this->redirectToRoute('coreshop_index');
        }

        $this->addFlash('success', 'coreshop.ui.item_removed');

        $this->getCartModifier()->removeItem($this->getCart(), $cartItem);
        $this->getCartManager()->persistCart($this->getCart());

        return $this->redirectToRoute('coreshop_cart_summary');
    }

    /**
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function summaryAction(Request $request)
    {
        return $this->renderTemplate('CoreShopFrontendBundle:Cart:summary.html.twig', [
            'cart' => $this->getCart(),
            'checkoutSteps' => $this->get('coreshop.checkout_manager')->getSteps(),
            'currentCheckoutStep' => 0
        ]);
    }

    /**
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function addPriceRuleAction(Request $request)
    {
        $code = $request->get('code');
        $cart = $this->getCartManager()->getCart();

        if (!$cart->hasItems()) {
            return $this->redirectToRoute('coreshop_cart_summary');
        }

        /**
         * 1. Find PriceRule for Code
         * 2. Check Validity
         * 3. Apply Price Rule to Cart.
         */
        $voucherCode = $this->getCartPriceRuleVoucherRepository()->findByCode($code);

        if (!$voucherCode instanceof CartPriceRuleVoucherCodeInterface) {
            $this->addFlash('error', 'coreshop.ui.error.voucher.not_found');
            return $this->redirectToRoute('coreshop_cart_summary');
        }

        $priceRule = $voucherCode->getCartPriceRule();

        if ($this->getCartPriceRuleProcessor()->process($this->getCartManager()->getCart(), $priceRule, $voucherCode)) {
            $this->getCartManager()->persistCart($this->getCart());
            $this->addFlash('success', 'coreshop.ui.success.voucher.stored');
        } else {
            $this->addFlash('error', 'coreshop.ui.error.voucher.invalid');
        }

        return $this->redirectToRoute('coreshop_cart_summary');
    }

    /**
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function removePriceRuleAction(Request $request)
    {
        $code = $request->get('code');
        $cart = $this->getCartManager()->getCart();

        $voucherCode = $this->getCartPriceRuleVoucherRepository()->findByCode($code);

        if (!$voucherCode instanceof CartPriceRuleVoucherCodeInterface) {
            return $this->redirectToRoute('coreshop_cart_summary');
        }

        $priceRule = $voucherCode->getCartPriceRule();

        $this->getCartPriceRuleUnProcessor()->unProcess($cart, $priceRule, $voucherCode);
        $this->getCartManager()->persistCart($this->getCart());

        return $this->redirectToRoute('coreshop_cart_summary');
    }

    /**
     * @param Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function createQuoteAction(Request $request)
    {
        $quote = $this->getQuoteFactory()->createNew();
        $quote = $this->getCartToQuoteTransformer()->transform($this->getCart(), $quote);

        return $this->redirectToRoute('coreshop_quote_detail', ['quote' => $quote->getId()]);
    }

    /**
     * @return \CoreShop\Component\Resource\Factory\PimcoreFactory
     */
    protected function getQuoteFactory()
    {
        return $this->get('coreshop.factory.quote');
    }

    protected function getCartToQuoteTransformer()
    {
        return $this->get('coreshop.order.transformer.cart_to_quote');
    }

    /**
     * @return CartPriceRuleProcessorInterface
     */
    protected function getCartPriceRuleProcessor()
    {
        return $this->get('coreshop.cart_price_rule.processor');
    }

    /**
     * @return CartPriceRuleUnProcessorInterface
     */
    protected function getCartPriceRuleUnProcessor()
    {
        return $this->get('coreshop.cart_price_rule.un_processor');
    }

    /**
     * @return StorageListModifierInterface
     */
    protected function getCartModifier()
    {
        return $this->get('coreshop.cart.modifier');
    }

    /**
     * @return CartPriceRuleVoucherRepositoryInterface
     */
    protected function getCartPriceRuleVoucherRepository()
    {
        return $this->get('coreshop.repository.cart_price_rule_voucher_code');
    }

    /**
     * @return \CoreShop\Component\Order\Model\CartInterface
     */
    protected function getCart()
    {
        return $this->getCartManager()->getCart();
    }

    /**
     * @return CartManagerInterface
     */
    protected function getCartManager()
    {
        return $this->get('coreshop.cart.manager');
    }
}
