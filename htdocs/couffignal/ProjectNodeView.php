<?php

declare(strict_types=1);

require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/couffignal/CoSousTraitant.php';
require_once DOL_DOCUMENT_ROOT.'/couffignal/FactureTools.php';
require_once DOL_DOCUMENT_ROOT.'/couffignal/FactureFournisseurTools.php';
if (isModEnabled('clientpayfourn')) dol_include_once('/clientpayfourn/class/linkclientpayfourn.class.php');
// Check compliance to module requirements
if (!isModEnabled('clientpayfourn')) {
	dol_syslog("Module clientpayfourn must be enabled for Co-Sous-traitant table to work", LOG_WARN);
	setEventMessages("", "Module clientpayfourn must be enabled for Co-Sous-traitant table to work", 'mesgs');
}

/**
 * Tools for ProjectNodeView class
 */
class ProjectNodeView
{
	/**
	 * Get the seeds - the starting point of the invoicing cycles - present in the project
	 *
	 * @param DoliDB $db
	 * @param Project $project Project object for which we search seeds
	 * @param array $properties Properties for get_element_list
	 * @param string $dates Forwarded
	 * @param string $datee Forwarded
	 * 
	 * @return array List of Invoices (Facture)
	 */
	public static function getCycleSeeds(DoliDB $db, Project $project, array $properties, string $dates, string $datee): array
	{
		// Collect all invoices
		$list_invoices = $project->get_element_list('invoice', $properties['table'], $properties['datefieldname'], $dates, $datee, 'fk_projet');

		// Assume only one serie of invoices
		$seeds = [];
		foreach ($list_invoices as $k => $id) {
			$inv = new Facture($db);
			$inv->fetch($id);
			if ($inv->is_first()) {
				$inv->fetchPreviousNextSituationInvoice();
				$seeds[] = $inv;
			}
		}

		return $seeds;
	}


	/**
	 * Load Invoices nodes related to an invoicing cycle
	 *
	 * @param array $seed List of nodes representing the FactureFournisseur related to invoicing cycle
	 * @param array $links Links object for the graph
	 * 	 * 
	 * @return array List of Invoices nodes (Facture)
	 */
	public static function loadInvoicesNodes(Facture $seed, array &$links): array
	{
		// Initialize invoice nodes with invoices
		$inv_nodes = [];
		foreach (array_merge([$seed], $seed->tab_next_situation_invoice) as $inv) {
		    $inv_nodes[$inv->ref] = [
		        'id' => $inv->ref,
		        'label' => $inv->ref . ' - ' . $inv->ref_client,
		        'obj' => $inv,
		        'type' => 'facture',
		    	'link' => '/compta/facture/card.php?facid='.$inv->id,
			    'tooltip' => $inv->getNomUrl(1),
			];
		}
		
		// Initialize links between nodes
		$keys = array_keys($inv_nodes);
		foreach ($keys as $index => $key) {
		    // Check if there is a next key to avoid out-of-bounds
		    if (isset($keys[$index + 1])) {
		        $links[] = [
		            'from' => $inv_nodes[$key]['id'],
		            'to' => $inv_nodes[$keys[$index + 1]]['id']
		        ];
		    }
		}

		return $inv_nodes;
	}


	/**
	 * Load Orders nodes for all invoice nodes of a cycle
	 *
	 * @param array $inv_nodes List of nodes representing the Factures in invoicing cycle
	 * @param array $links Links object for the graph
	 * 
	 * @return array List of Orders nodes (Commande)
	 */
	public static function loadOrdersNodes(DoliDB $db, array $inv_nodes, array &$links): array
	{
		$order_nodes = [];
		foreach ($inv_nodes as $i => $node) {
			$orders = FactureTools::getTotalHtOrdersLinkedToInvoice($db, $node['obj'], True);
			// Add new nodes
			foreach ($orders as $o) {
			    $order_nodes[$o['obj']->ref] = [
			        'id' => $o['obj']->ref,
			        'label' => $o['obj']->ref . ' - ' . $o['obj']->ref_client,
			        'obj' => $o['obj'],
			        'type' => 'order',
			        'link' => '/commande/card.php?id='.$o['obj']->id,
			        'tooltip' => $o['obj']->getNomUrl(1),
			    ];
				// Create links 
			    $o['obj']->fetchObjectLinked();
			    foreach ($o['obj']->linkedObjects['facture'] as $id => $facture) {
					$links[] = [
			            'from' => $o['obj']->ref,
			            'to' => $facture->ref
			        ];
		        }
			}
		}

		return $order_nodes;
	}


	/**
	 * Load Supplier Invoices nodes for all invoice nodes of a cycle
	 *
	 * @param array $inv_nodes List of nodes representing the Factures in invoicing cycle
	 * @param array $links Links object for the graph
	 * 
	 * @return array List of Supplier Invoices nodes (FactureFournisseur)
	 */
	public static function loadSupplierInvoicesNodes(DoliDB $db, array $inv_nodes, array &$links): array
	{
		$su_inv_nodes = [];
		foreach ($inv_nodes as $i => $node) {
			$obj = new LinkClientPayFourn($db);
			$su_invs = $obj->getLinkedObjects($node['obj'], $db);

			// Add new nodes
			foreach ($su_invs as $su_i) {
			    $su_inv_nodes[$su_i->ref] = [
			        'id' => $su_i->ref,
			        'label' => $su_i->ref . ' - ' . $su_i->ref_supplier,
			        'obj' => $su_i,
			        'type' => 'supplier_invoice',
			        'link' => '/fourn/facture/card.php?facid='.$su_i->id,
			        'tooltip' => $su_i->getNomUrl(1),
			    ];
				// Create links 
				$links[] = [
		            'from' => $node['id'],
		            'to' => $su_i->ref
		        ];
			}
		}

		return $su_inv_nodes;
	}


	/**
	 * Load Supplier Orders nodes for all supplier invoice nodes related to an invoicing cycle
	 *
	 * @param array $su_inv_nodes List of nodes representing the FactureFournisseur related to invoicing cycle
	 * @param array $links Links object for the graph
	 * 
	 * @return array List of Supplier Orders nodes (Commande)
	 */
	public static function loadSupplierOrdersNodes(array $su_inv_nodes, array &$links): array
	{
		$su_order_nodes = [];
		foreach ($su_inv_nodes as $i => $node) {
			$su_orders = FactureFournisseurTools::getOrdersValidatedFromFacturesFourn([$node['obj']]);
			// Add new nodes
			foreach ($su_orders as $o) {
			    $su_order_nodes[$o->ref] = [
			        'id' => $o->ref,
			        'label' => $o->ref . ' - ' . $o->ref_supplier,
			        'obj' => $o,
			        'type' => 'supplier_order',
			        'link' => '/fourn/commande/card.php?id='.$o->id,
			        'tooltip' => $o->getNomUrl(1),
			    ];
				// Create links 
			    $o->fetchObjectLinked();
			    foreach ($o->linkedObjects['invoice_supplier'] as $id => $su_inv) {
					$links[] = [
			            'from' => $o->ref,
			            'to' => $su_inv->ref
			        ];
		        }
			}
		}

		return $su_order_nodes;
	}

}