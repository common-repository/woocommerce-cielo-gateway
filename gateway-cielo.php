<?php
/*
Plugin Name: WooCommerce Cielo Gateway
Plugin URI: http://www.quadrosolucoes.com.br
Description: The Cielo payment gateway plugin for WooCommerce. Curl support and a server with SSL support and an SSL certificate is required (for security reasons) for this gateway to function. 
Version: 1.1
Author: Tiago Alves
Author URI: http://www.quadrosolucoes.com.br
*/

add_action('plugins_loaded', 'woocommerce_cielo_init', 0);

function woocommerce_cielo_init() {

	if ( ! class_exists( 'WC_Payment_Gateway' ) ) { return; }

	require_once(WP_PLUGIN_DIR . "/" . plugin_basename( dirname(__FILE__)) . '/classes/include.php');
	require_once(WP_PLUGIN_DIR . "/" . plugin_basename( dirname(__FILE__)) . '/classes/pedido.php');
	require_once(WP_PLUGIN_DIR . "/" . plugin_basename( dirname(__FILE__)) . '/classes/logger.php');
	require_once(WP_PLUGIN_DIR . "/" . plugin_basename( dirname(__FILE__)) . '/classes/errorHandling.php');
	//require_once(WP_PLUGIN_DIR . "/" . plugin_basename( dirname(__FILE__)) . '/classes/cielo-response.php');
	
	/**
 	* Gateway class
 	**/
	class WC_Gateway_Cielo extends WC_Payment_Gateway {
	
		var $avaiable_countries = array(
			'BR' => array(
				'visa' => 'Visa',
				'mastercard' => 'MasterCard',
				'diners' => 'Diners',
				'discover' => 'Discover',
				'elo' => 'Elo',
				'amex' => 'American Express'
			),
		);

		var $liveurl = 'https://ecommerce.cielo.com.br/servicos/ecommwsec.do';
		var $testurl = 'https://qasecommerce.cielo.com.br/servicos/ecommwsec.do';
		var $testmode;
		var $ec_numero;
		var $ec_chave;
		var $tipo_parcelamento = '2'; // 2 - loja , 3 - administradora
		var $capturar_automaticamente = 'true'; // 'true' , 'false'
		var $indicador_autorizacao = '3'; // 3 - Autorizar Direto , 2 - Autorizar transação autenticada e não-autenticada , 0 - Somente autenticar a transação , 1 - Autorizar transação somente se autenticada		
		var $tentar_autenticar = 'nao'; // 'sim' , 'nao'
	
		
		
		function __construct() { 
			
			$this->id				= 'cielo';
			$this->method_title 	= __('Cielo', 'woothemes');
			$this->icon 			= WP_PLUGIN_URL . "/" . plugin_basename( dirname(__FILE__)) . '/images/cards.png';
			$this->has_fields 		= true;
			
			// Load the form fields
			$this->init_form_fields();
			
			// Load the settings.
			$this->init_settings();
			
			// Get setting values
			$this->title 			= $this->settings['title'];
			$this->description 		= $this->settings['description'];
			$this->enabled 			= $this->settings['enabled'];
			$this->ec_numero	 	= $this->settings['ec_numero'];
			$this->ec_chave		 	= $this->settings['ec_chave'];
			$this->testmode 		= $this->settings['testmode'];

			// Hooks
			add_action( 'admin_notices', array( &$this, 'ssl_check') );
			if ( is_admin() ) {
			  add_action( 'woocommerce_update_options_payment_gateways',              array( $this, 'process_admin_options' ) );  // WC < 2.0
			  add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );  // WC >= 2.0
			}
		}
		
		
		static function get_certificado_ssl() {
			return WP_PLUGIN_DIR . "/" . plugin_basename( dirname(__FILE__)) . '/ssl/VeriSignClass3PublicPrimaryCertificationAuthority-G5.crt';
		}
		
		function get_cielo_url() {
			if ($this->testmode) {
				return $this->testurl;
			}	
			return $this->liveurl;
		}
		
		/**
	 	* Check if SSL is enabled and notify the user if SSL is not enabled
	 	**/
		function ssl_check() {
	     
		if (get_option('woocommerce_force_ssl_checkout')=='no' && $this->enabled=='yes') :
		
			echo '<div class="error"><p>'.sprintf(__('Cielo está habilitado, mas a opção <a href="%s">forçar SSL</a> está desabilitada. Habilite o SSL e certifique-se que o servidor tem um certificado SSL válido - Cielo só funcionará em modo teste.', 'woothemes'), admin_url('admin.php?page=woocommerce')).'</p></div>';
		
		endif;
		}
		
		/**
	     * Initialize Gateway Settings Form Fields
	     */
	    function init_form_fields() {
	    
	    	$this->form_fields = array(
				'title' => array(
								'title' => __( 'Título', 'woothemes' ), 
								'type' => 'text', 
								'description' => __( 'Título que o cliente verá durante o processo de pagamento.', 'woothemes' ), 
								'default' => __( 'Cartão de Crédito', 'woothemes' )
							), 
				'enabled' => array(
								'title' => __( 'Habilitar/Desabilitar', 'woothemes' ), 
								'label' => __( 'Habilitar Cielo', 'woothemes' ), 
								'type' => 'checkbox', 
								'description' => '', 
								'default' => 'no'
							), 
				'description' => array(
								'title' => __( 'Descrição', 'woothemes' ), 
								'type' => 'text', 
								'description' => __( 'Título que o cliente verá durante o processo de pagamento.', 'woothemes' ), 
								'default' => 'Pague com o seu cartão de crédito.'
							),  
				'testmode' => array(
								'title' => __( 'Modo Teste', 'woothemes' ), 
								'label' => __( 'Habilitar modo teste', 'woothemes' ), 
								'type' => 'checkbox', 
								'description' => __( '
										Processa as transações em modo teste.<br/>
										Loja: 1006993069 Chave: 25fbb99741c739dd84d7b06ec78c9bac718838630f30b112d033ce2e621b34f3 <br/>
										Cielo: 1001734898 Chave: e84827130b9837473681c2787007da5914d6359947015a5cdb2b8843db0fa832
										', 'woothemes' ), 
								'default' => 'yes'
							), 
				'ec_numero' => array(
								'title' => __( 'Número da Loja', 'woothemes' ), 
								'type' => 'text', 
								'description' => __('Número de credenciamento da loja com a Cielo.', 'woothemes' ), 
								'default' => '1001734898'
							), 
				'ec_chave' => array(
								'title' => __( 'Chave de acesso', 'woothemes' ), 
								'type' => 'text', 
								'description' => __('Chave de acesso da loja atribuída pela Cielo.', 'woothemes' ), 
								'default' => 'e84827130b9837473681c2787007da5914d6359947015a5cdb2b8843db0fa832'
							),
				);
	    }
	    
	    /**
		 * Admin Panel Options 
		 * - Options for bits like 'title' and availability on a country-by-country basis
		 */
		function admin_options() {
	    	?>
	    	<h3><?php _e( 'Cielo', 'woothemes' ); ?></h3>
	    	<p><?php _e( 'Utiliza a rede Cielo como meio de pagamento para Cartões de Crédito.', 'woothemes' ); ?></p>
	    	<table class="form-table">
	    		<?php $this->generate_settings_html(); ?>
			</table><!--/.form-table-->
	    	<?php
	    }
		
		/**
	     * Check if this gateway is enabled and available in the user's country
	     */
		function is_available() {
		
			if ($this->enabled=="yes") :
			
				if (get_option('woocommerce_force_ssl_checkout')=='no' && $this->testmode == 'no') return false;
				
				$user_country = $this->get_country_code();
				if(empty($user_country)) {
					return false;
				}
			
				return isset($this->avaiable_countries[$user_country]);
				
			endif;	
			
			return false;
		}
		
		/**
	     * Get the users country either from their order, or from their customer data
	     */
		function get_country_code() {
			global $woocommerce;
			
			if(isset($_GET['order_id'])) {
			
				$order = new WC_Order($_GET['order_id']);
	
				return $order->billing_country;
				
			} elseif ($woocommerce->customer->get_country()) {
				
				return $woocommerce->customer->get_country();
			
			}
			
			return NULL;
		}
	
		/**
	     * Payment form on checkout page
	     */
		function payment_fields() {
			global $woocommerce;
			$user_country = $this->get_country_code();
			
			if(empty($user_country)) :
				echo __('Selecione o País para visualizar as formas de pagamento.', 'woothemes');
				return;
			endif;
			
			if (!isset($this->avaiable_countries[$user_country])) :
				echo __('Cielo não está disponível no seu País.', 'woothemes');
				return;
			endif;
			
			$available_cards = $this->avaiable_countries['BR'];
			
			?>
			<?php if ($this->testmode=='yes') : ?><p><?php _e('MODO TESTE', 'woothemes'); ?></p><?php endif; ?>
			<?php if ($this->description) : ?><p><?php echo $this->description; ?></p><?php endif; ?>
			<fieldset>
				
				<p class="form-row form-row-first">
					<label for="cartaoNumero"><?php echo __("Número do Cartão de Crédito", 'woocommerce') ?> <span class="required">*</span></label>
					<input type="text" class="input-text" name="cartaoNumero" maxlength="16" />
					<span class="help">somente os números</span>
				</p>
				<p class="form-row form-row-last">
					<label for="codigoBandeira"><?php echo __("Bandeira do Cartão", 'woocommerce') ?> <span class="required">*</span></label>
					<select id="codigoBandeira" name="codigoBandeira">
						<?php foreach ($available_cards as $card => $cardName) : ?>
									<option value="<?php echo $card ?>"><?php echo $cardName; ?></options>
						<?php endforeach; ?>
					</select>
				</p>
				
				<div class="clear"></div>
				<p class="form-row form-row-first">
					<label for="cartaoValidadeMes"><?php echo __("Validade do Cartão", 'woocommerce') ?> <span class="required">*</span></label>
					<select name="cartaoValidadeMes" id="cartaoValidadeMes">
						<option value=""><?php _e('Mês', 'woocommerce') ?></option>
						<?php
							$months = array();
							for ($i = 1; $i <= 12; $i++) {
							    $timestamp = mktime(0, 0, 0, $i, 1);
							    $months[date('m', $timestamp)] = date('F', $timestamp);
							}
							foreach ($months as $num => $name) {
					            printf('<option value="%s">%s</option>', $num, __($name));
					        }
					        
						?>
					</select>
					<select name="cartaoValidadeAno" id="cartaoValidadeAno">
						<option value=""><?php _e('Ano', 'woocommerce') ?></option>
						<?php
							$years = array();
							for ($i = date('Y'); $i <= date('Y') + 15; $i++) {
							    printf('<option value="%u">%u</option>', $i, $i);
							}
						?>
					</select>
				</p>
				<p class="form-row form-row-last">
					<label for="cartaoCodigoSeguranca"><?php _e("Código de Segurança do Cartão", 'woocommerce') ?> <span class="required">*</span></label>
					<input type="text" class="input-text" id="cartaoCodigoSeguranca" name="cartaoCodigoSeguranca" maxlength="4" style="width:45px" />
					<span class="help cielo_card_csc_description"></span>
				</p>
				<div class="clear"></div>
				<p class="form-row">
					<label for="formaPagamento"><?php _e("Opções de Parcelamento", 'woocommerce') ?> <span class="required">*</span></label>
					<select name="formaPagamento" id="formaPagamento">
						<?php $valor = $woocommerce->cart->total; ?>
						<option value="1"><?php _e('Crédito à Vista', 'woocommerce') ?></option>
						<?php if(intval($valor) >= 99): ?><option value="2"><?php _e('2x sem juros', 'woocommerce') ?></option><?php endif; ?>
						<?php if(intval($valor) >= 158): ?><option value="3"><?php _e('3x sem juros', 'woocommerce') ?></option><?php endif; ?>
						<?php if(intval($valor) >= 288): ?><option value="4"><?php _e('4x sem juros', 'woocommerce') ?></option><?php endif; ?>
						<?php if(intval($valor) >= 358): ?><option value="5"><?php _e('5x sem juros', 'woocommerce') ?></option><?php endif; ?>
						<?php if(intval($valor) > 358): ?><option value="6"><?php _e('6x sem juros', 'woocommerce') ?></option><?php endif; ?>
					</select>
					
				</p>
				<div class="clear"></div>
			</fieldset>
			<script type="text/javascript">
			
				function toggle_csc() {
					var card_type = jQuery("#codigoBandeira").val();
					var csc = jQuery("#cielo_card_csc").parent();
			
					if(card_type == "Visa" || card_type == "MasterCard" || card_type == "Discover" || card_type == "American Express" ) {
						csc.fadeIn("fast");
					} else {
						csc.fadeOut("fast");
					}
					
					if(card_type == "Visa" || card_type == "MasterCard" || card_type == "Discover") {
						jQuery('.cielo_card_csc_description').text("<?php _e('3 dígitos localizados do verso do cartão.', 'woocommerce'); ?>");
					} else if ( cardType == "American Express" ) {
						jQuery('.cielo_card_csc_description').text("<?php _e('4 dígitos localizados do verso do cartão.', 'woocommerce'); ?>");
					} else {
						jQuery('.cielo_card_csc_description').text('');
					}
				}
			
				jQuery("#cielo_card_type").change(function(){
					toggle_csc();
				}).change();
			
			</script>
			<?php
		}
		
		/**
	     * Process the payment
	     */
		function process_payment($order_id) {
			global $woocommerce;
			
			$order = new WC_Order( $order_id );
			
			$billing_country 		= isset($_POST['billing_country']) ? $_POST['billing_country'] : '';
			$codigoBandeira 		= isset($_POST['codigoBandeira']) ? $_POST['codigoBandeira'] : '';
			$cartaoNumero 			= isset($_POST['cartaoNumero']) ? $_POST['cartaoNumero'] : '';
			$cartaoCodigoSeguranca	= isset($_POST['cartaoCodigoSeguranca']) ? $_POST['cartaoCodigoSeguranca'] : '';
			$cartaoValidadeMes		= isset($_POST['cartaoValidadeMes']) ? $_POST['cartaoValidadeMes'] : '';
			$cartaoValidadeAno 		= isset($_POST['cartaoValidadeAno']) ? $_POST['cartaoValidadeAno'] : '';
			$formaPagamento			= isset($_POST["formaPagamento"]) ? $_POST['formaPagamento'] : '';
			$cartaoValidade = $cartaoValidadeAno . $cartaoValidadeMes;
	

			// Format card number
			$cartaoNumero = str_replace(array(' ', '-'), '', $cartaoNumero);
	
			// Validate plugin settings
			if (!$this->validate_settings()) :
				$cancelNote = __('O Pagamento foi cancelado defido a erro de configuração do meio de pagamento.', 'woothemes');
				$order->add_order_note( $cancelNote );
		
				$woocommerce->add_error(__('O Pagamento foi cancelado defido a erro de configuração do meio de pagamento.', 'woothemes'));
				return false;
			endif;
	
			// Send request to cielo
			try {
				$url = $this->liveurl;
				if ($this->testmode == 'yes') :
					$url = $this->testurl;
				endif;
				
				
				$Pedido = new Pedido();
				
				// Lê dados do $_POST
				$Pedido->formaPagamentoBandeira = $codigoBandeira;
				if($formaPagamento != "A" && $formaPagamento != "1")
				{
					$Pedido->formaPagamentoProduto = $this->tipo_parcelamento;
					$Pedido->formaPagamentoParcelas = $formaPagamento;
				}
				else
				{
					$Pedido->formaPagamentoProduto = $formaPagamento;
					$Pedido->formaPagamentoParcelas = 1;
				}
				
				$Pedido->dadosEcNumero = $this->ec_numero;
				$Pedido->dadosEcChave = $this->ec_chave;
				
				$Pedido->capturar = $this->capturar_automaticamente;
				$Pedido->autorizar = $this->indicador_autorizacao;
				
				
				$Pedido->dadosPortadorNumero = $cartaoNumero;
				$Pedido->dadosPortadorVal = $cartaoValidade;
				// Verifica se Código de Segurança foi informado e ajusta o indicador corretamente
				if (empty($cartaoCodigoSeguranca))
				{
					$Pedido->dadosPortadorInd = "0";
				}
				else if ($Pedido->formaPagamentoBandeira == "mastercard")
				{
					$Pedido->dadosPortadorInd = "1";
				}
				else
				{
					$Pedido->dadosPortadorInd = "1";
				}
				$Pedido->dadosPortadorCodSeg = $cartaoCodigoSeguranca;
				
				$Pedido->dadosPedidoNumero = $order_id;
				$Pedido->dadosPedidoValor = intVal(($order->order_total * 100));
				
				$Pedido->urlRetorno = ReturnURL(); //include.php
				
				// ENVIA REQUISIÇÃO SITE CIELO
				if($this->tentar_autenticar == "sim") // TRANSAÇÃO
				{
					$objResposta = $Pedido->RequisicaoTransacao(true);
				}
				else // AUTORIZAÇÃO DIRETA
				{
					$objResposta = $Pedido->RequisicaoTid();
				
					$Pedido->tid = $objResposta->tid;
					$Pedido->pan = $objResposta->pan;
					$Pedido->status = $objResposta->status;
				
					$objResposta = $Pedido->RequisicaoAutorizacaoPortador();
				}
				
				$Pedido->tid = $objResposta->tid;
				$Pedido->pan = $objResposta->pan;
				$Pedido->status = $objResposta->status;
				
				$urlAutenticacao = "url-autenticacao";
				$Pedido->urlAutenticacao = $objResposta->$urlAutenticacao;
				
				// Serializa Pedido e guarda na SESSION
				//$StrPedido = $Pedido->ToString();
				//$_SESSION["pedidos"]->append($StrPedido);
				
				
				if($this->tentar_autenticar == "sim") // TRANSAÇÃO
				{
					echo '<script type="text/javascript">
					window.location.href = "' . $Pedido->urlAutenticacao . '"
					</script>';
				}
				else // AUTORIZAÇÃO DIRETA
				{
					// Consulta situação da transação
					$objResposta = $Pedido->RequisicaoConsulta();
					
					// Atualiza status
					$Pedido->status = $objResposta->status;
					
					if($Pedido->status == '4' || $Pedido->status == '6') {
						$finalizacao = true;
						
						$order->add_order_note( __( 'Pagamento Aprovado pela Cielo.', 'woothemes' ) );
						
						$order->payment_complete();
						
						$woocommerce->cart->empty_cart();
						
						return array(
								'result' 	=> 'success',
								'redirect'	=> add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(get_option('woocommerce_thanks_page_id'))))
						);
						
					} else {
						
						$order->add_order_note( __( 'Pagamento Falhou.', 'woothemes' ) );
						$order->update_status( 'failed', __( 'Pagamento Falhou.', 'woothemes' ) );
						$woocommerce->add_error(__('Erro no Pagamento', 'woothemes') . ': ' . $Pedido->getStatus() . '');
						$finalizacao = false;
						return;
					}
				}
				
				
			} catch(Exception $e) {
				$woocommerce->add_error(__('There was a connection error', 'woothemes') . ': "' . $e->getMessage() . '"');
				return;
			}
		}
	
		/**
	     * Validate the payment form
	     */
		function validate_fields() {
			global $woocommerce;
												
			$billing_country 	= isset($_POST['billing_country']) ? $_POST['billing_country'] : '';
			$codigoBandeira 			= isset($_POST['codigoBandeira']) ? $_POST['codigoBandeira'] : '';
			$cartaoNumero 		= isset($_POST['cartaoNumero']) ? $_POST['cartaoNumero'] : '';
			$cartaoCodigoSeguranca 			= isset($_POST['cartaoCodigoSeguranca']) ? $_POST['cartaoCodigoSeguranca'] : '';
			$cartaoValidadeMes		= isset($_POST['cartaoValidadeMes']) ? $_POST['cartaoValidadeMes'] : '';
			$cartaoValidadeAno 		= isset($_POST['cartaoValidadeAno']) ? $_POST['cartaoValidadeAno'] : '';
	
			// Check if payment is avaiable for given country and card
			if (!isset($this->avaiable_countries[$billing_country])) {
				$woocommerce->add_error(__('A forma de pagamento escolhida não está disponível para o seu País.', 'woothemes'));
				return false;
			}
			
			// Check card type is available
			$available_cards = $this->avaiable_countries[$billing_country];
			if (!array_key_exists($codigoBandeira, $available_cards)) {
				$woocommerce->add_error(__('A Bandeira do Cartão escolhida não está disponível para o seu País.', 'woothemes'));
				return false;
			}
	
			// Check card security code
			if(!ctype_digit($cartaoCodigoSeguranca)) {
				$woocommerce->add_error(__('O Código de Segurança do Cartão está incorreto. Informe apenas números.', 'woothemes'));
				return false;
			}
	
			if((strlen($cartaoCodigoSeguranca) != 3 && array_key_exists($codigoBandeira, array('visa', 'mastercard', 'discover'))) || (strlen($cartaoCodigoSeguranca) != 4 && $codigoBandeira == 'amex')) {
				$woocommerce->add_error(__('O Código de Segurança do Cartão está incorreto (quantidade de números)', 'woothemes'));
				return false;
			}
	
			// Check card expiration data
			if(!ctype_digit($cartaoValidadeMes) || !ctype_digit($cartaoValidadeAno) ||
				 $cartaoValidadeMes > 12 ||
				 $cartaoValidadeMes < 1 ||
				 $cartaoValidadeAno < date('Y') ||
				 $cartaoValidadeAno > date('Y') + 20
			) {
				$woocommerce->add_error(__('A Validade do Cartão está incorreta.', 'woothemes'));
				return false;
			}
	
			// Check card number
			$cartaoNumero = str_replace(array(' ', '-'), '', $cartaoNumero);
	
			if(empty($cartaoNumero) || !ctype_digit($cartaoNumero) || strlen($cartaoNumero) != 16) {
				$woocommerce->add_error(__('O Número do Cartão de Crédito está incorreto.', 'woothemes'));
				return false;
			}
	
			return true;
		}
		
		/**
	     * Validate plugin settings
	     */
		function validate_settings() {
			$currency = get_option('woocommerce_currency');
	
			if (!in_array($currency, array('BRL'))) {
				return false;
			}
		
			if (!$this->ec_numero || !$this->ec_chave) {
				return false;
			}
	
			return true;
		}
		
		/**
	     * Get user's IP address
	     */
		function get_user_ip() {
			if (!empty($_SERVER['HTTP_X_FORWARD_FOR'])) {
				return $_SERVER['HTTP_X_FORWARD_FOR'];
			} else {
				return $_SERVER['REMOTE_ADDR'];
			}
		}

	} // end woocommerce_cielo
	
	/**
 	* Add the Gateway to WooCommerce
 	**/
	function add_cielo_gateway($methods) {
		$methods[] = 'WC_Gateway_Cielo';
		return $methods;
	}
	
	add_filter('woocommerce_payment_gateways', 'add_cielo_gateway' );
} 
