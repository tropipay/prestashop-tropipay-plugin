
<img src="{$this_path|escape:'htmlall'}img/tarjetas.png" /><br /><br />
{if $status == 'ok'}
	<p>{l s='Your order on %s is complete.' sprintf=$shop_name mod='tropipay'}
		<br /><br />- {l s='Payment amount.' mod='tropipay'} <span class="price"><strong>{$total_to_pay|escape:'htmlall'}</strong></span>
		<br /><br />- N# <span class="price"><strong>{$id_order|escape:'htmlall'}</strong></span>
		<br /><br />{l s='An email has been sent to you with this information.' mod='tropipay'}
		<br /><br />{l s='For any questions or for further information, please contact our' mod='tropipay'} <a href="{$link->getPageLink('contact', true)|escape:'html'}">{l s='customer service department.' mod='tropipay'}</a>.
	</p>
{else}
	<p class="warning">
		{l s='We have noticed that there is a problem with your order. If you think this is an error, you can contact our' mod='tropipay'} 
		<a href="{$link->getPageLink('contact', true)|escape:'html'}">{l s='customer service department.' mod='tropipay'}</a>.
	</p>
{/if}
