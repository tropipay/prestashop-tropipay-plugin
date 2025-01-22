

{if $smarty.const._PS_VERSION_ >= 1.6}

<div class="row">
	<div class="col-xs-12">
		<p class="payment_module">
			<a class="bankwire" href="javascript:$('#tropipay_form').submit();" title="{l s='Conectar con el TPV' mod='tropipay'}">	
				<img src="{$module_dir|escape:'htmlall'}img/tarjetas.png" alt="{l s='Conectar con el TPV' mod='tropipay'}" height="48" />
				{l s='Pagar con tarjeta' mod='tropipay'}
			</a>
		</p>
	</div>
</div>
{else}
<p class="payment_module">
	<a class="bankwire" href="javascript:$('#tropipay_form').submit();" title="{l s='Conectar con el TPV' mod='tropipay'}">	
		<img src="{$module_dir|escape:'htmlall'}img/tarjetas.png" alt="{l s='Conectar con el TPV' mod='tropipay'}" height="48" />
		{l s='Pagar con tarjeta' mod='tropipay'}
	</a>
</p>
{/if}

<form action="{$urltpvd|escape:'htmlall'}" method="get" id="tropipay_form" class="hidden">	

</form>
