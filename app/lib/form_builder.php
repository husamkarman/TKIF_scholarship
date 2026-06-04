<?php

declare(strict_types=1);

function form_builder_field_catalog(): array
{
  return [
    'text' => 'Single line text',
    'textarea' => 'Paragraph text',
    'number' => 'Numeric value',
    'email' => 'Email address',
    'date' => 'Date picker',
    'phone' => 'Country code + phone number',
    'select' => 'Select dropdown',
    'radio' => 'Single choice radio',
    'checkbox' => 'Multi-choice checkbox',
  ];
}

function form_builder_starter_templates(): array
{
  return [
    'basic_application' => [
      'label' => 'Basic Application',
      'description' => 'General-purpose starter with identity, contact, and motivation fields.',
      'schema' => [
        ['name' => 'full_name', 'label' => 'Full Name', 'type' => 'text', 'required' => true],
        ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
        ['name' => 'phone', 'label' => 'Phone Number', 'type' => 'text', 'required' => true],
        ['name' => 'gpa', 'label' => 'Current GPA', 'type' => 'number', 'required' => true],
        ['name' => 'motivation', 'label' => 'Why do you deserve this scholarship?', 'type' => 'textarea', 'required' => true],
      ],
    ],
    'research_track' => [
      'label' => 'Research Track',
      'description' => 'Starter template for research-oriented scholarships.',
      'schema' => [
        ['name' => 'full_name', 'label' => 'Full Name', 'type' => 'text', 'required' => true],
        ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
        ['name' => 'research_area', 'label' => 'Research Area', 'type' => 'select', 'required' => true, 'options' => ['AI', 'Biotech', 'Energy', 'Policy']],
        ['name' => 'publication_count', 'label' => 'Publication Count', 'type' => 'number', 'required' => false],
        ['name' => 'proposal_summary', 'label' => 'Proposal Summary', 'type' => 'textarea', 'required' => true],
      ],
    ],
    'needs_based' => [
      'label' => 'Needs-Based Support',
      'description' => 'Starter template for financial-needs applications.',
      'schema' => [
        ['name' => 'full_name', 'label' => 'Full Name', 'type' => 'text', 'required' => true],
        ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
        ['name' => 'household_income', 'label' => 'Household Income (Monthly)', 'type' => 'number', 'required' => true],
        ['name' => 'dependents', 'label' => 'Number of Dependents', 'type' => 'number', 'required' => true],
        ['name' => 'support_reason', 'label' => 'Support Justification', 'type' => 'textarea', 'required' => true],
      ],
    ],
  ];
}

function form_builder_starter_template_keys(): array
{
  return array_keys(form_builder_starter_templates());
}

function form_builder_starter_template(string $key): array
{
  $templates = form_builder_starter_templates();
  if (!isset($templates[$key])) {
    return $templates['basic_application'];
  }
  return $templates[$key];
}

function form_builder_starter_template_json(string $key): string
{
  $template = form_builder_starter_template($key);
  return (string)json_encode($template['schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
