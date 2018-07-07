<?php
/*
 *
 * Copyright 2012 Human Resource Information System
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 * MA 02110-1301, USA.
 *
 * @since 2012
 * @author John Francis Mukulu <john.f.mukulu@gmail.com>
 *
 */
namespace Hris\PublicRecordsBundle\Form;

use Doctrine\Tests\Common\Annotations\False;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;
use Doctrine\ORM\EntityRepository;

class HistoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('field','entity',array(
                'mapped' => False,
                'class'=>'HrisFormBundle:Field',
                'empty_value' => '--SELECT--',
                'query_builder'=>function(EntityRepository $er) {
                    return $er->createQueryBuilder('field')
                        ->where('field.hashistory=True')
                        ->orderBy('field.name','ASC');
                }
            ))
            ->add('startdate','date',array(
                'required'=>true,
                'widget' => 'single_text',
                'format' => 'dd/MM/yyyy',
                'attr' => array('class' => 'date')
            ))
            ->add('enddate', 'date', array(
                'required'=>False,
                'widget' => 'single_text',
                'format' => 'dd/MM/yyyy',
                'attr' => array('class' => 'enddate')
            ))
            ->add('entitled_payment', 'choice', array(
                'choices'   => array('yes' => 'Entitled for Payment', 'no' => 'Not Entitled for Payment'),
                'required'  => false,
            ))
            ->add('reason', 'textarea', array(
                'required'=>True,
            ))
            ->add('updaterecord','checkbox',array(
                'required'=>False,
                'mapped' => False,
            ))
        ;
    }

    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Hris\PublicRecordsBundle\Entity\History'
        ));
    }

    public function getName()
    {
        return 'hris_recordsbundle_historytype';
    }
}
