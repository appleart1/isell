<?php

$this->view->a->all=getAll($this->view->a);
$this->view->p->all=getAll($this->view->p);

function getAll( $comp ) {
    $all ="$comp->company_name \n$comp->company_jaddress";
    $all.=$comp->company_phone?", тел.:{$comp->company_phone}":'';
    $all.=$comp->company_bank_account?", Р/р:{$comp->company_bank_account}":'';
    $all.=$comp->company_bank_name?" в {$comp->company_bank_name}":'';
    $all.=$comp->company_bank_id?", МФО:{$comp->company_bank_id}":'';
    $all.=$comp->company_tax_id?", IПН:{$comp->company_tax_id}":'';
    $all.=$comp->company_code?", ЄДРПОУ:{$comp->company_code}":'';
    $all.=$comp->company_email?", E-mail:{$comp->company_email}":'';
    $all.=$comp->company_web?",{$comp->company_web}":'';
    return $all;
}
