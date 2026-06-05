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
    'welcome' => 'Welcome section node',
    'agreement' => 'Agreement section node',
    'section' => 'Section break node',
    'form' => 'Form section node',
    'thank_you' => 'Thank-you section node',
  ];
}

function form_builder_starter_templates(): array
{
  return [
    'basic_application' => [
      'label' => 'Basic Application',
      'description' => 'General-purpose starter with identity, contact, and motivation fields.',
      'schema' => [
        ['name' => 'welcome_intro', 'label' => 'Welcome', 'type' => 'welcome', 'help_text' => '<p>Welcome to this scholarship application.</p>'],
        ['name' => 'form_main', 'label' => 'Application Form', 'type' => 'form', 'help_text' => '<p>Please complete all required fields below.</p>'],
        ['name' => 'full_name', 'label' => 'Full Name', 'type' => 'text', 'required' => true],
        ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
        ['name' => 'phone', 'label' => 'Phone Number', 'type' => 'text', 'required' => true],
        ['name' => 'gpa', 'label' => 'Current GPA', 'type' => 'number', 'required' => true],
        ['name' => 'motivation', 'label' => 'Why do you deserve this scholarship?', 'type' => 'textarea', 'required' => true],
        ['name' => 'agreement_terms', 'label' => 'Applicant Agreement', 'type' => 'agreement', 'required' => true, 'help_text' => '<p>I confirm all submitted information is accurate.</p>'],
        ['name' => 'thank_you_note', 'label' => 'Thank You', 'type' => 'thank_you', 'help_text' => '<p>Thank you for applying. Our team will review your submission.</p>'],
      ],
    ],
    'research_track' => [
      'label' => 'Research Track',
      'description' => 'Starter template for research-oriented scholarships.',
      'schema' => [
        ['name' => 'welcome_research', 'label' => 'Welcome', 'type' => 'welcome', 'help_text' => '<p>Welcome to the research track scholarship.</p>'],
        ['name' => 'form_research', 'label' => 'Research Application', 'type' => 'form', 'help_text' => '<p>Share your research profile and proposal details.</p>'],
        ['name' => 'full_name', 'label' => 'Full Name', 'type' => 'text', 'required' => true],
        ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
        ['name' => 'research_area', 'label' => 'Research Area', 'type' => 'select', 'required' => true, 'options' => ['AI', 'Biotech', 'Energy', 'Policy']],
        ['name' => 'publication_count', 'label' => 'Publication Count', 'type' => 'number', 'required' => false],
        ['name' => 'proposal_summary', 'label' => 'Proposal Summary', 'type' => 'textarea', 'required' => true],
        ['name' => 'agreement_research', 'label' => 'Research Agreement', 'type' => 'agreement', 'required' => true, 'help_text' => '<p>I agree to the program terms and disclosure policy.</p>'],
        ['name' => 'thank_you_research', 'label' => 'Thank You', 'type' => 'thank_you', 'help_text' => '<p>Thanks for submitting your research application.</p>'],
      ],
    ],
    'needs_based' => [
      'label' => 'Needs-Based Support',
      'description' => 'Starter template for financial-needs applications.',
      'schema' => [
        ['name' => 'welcome_needs', 'label' => 'Welcome', 'type' => 'welcome', 'help_text' => '<p>Welcome to the needs-based support application.</p>'],
        ['name' => 'form_needs', 'label' => 'Financial Information', 'type' => 'form', 'help_text' => '<p>Please provide accurate financial details.</p>'],
        ['name' => 'full_name', 'label' => 'Full Name', 'type' => 'text', 'required' => true],
        ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
        ['name' => 'household_income', 'label' => 'Household Income (Monthly)', 'type' => 'number', 'required' => true],
        ['name' => 'dependents', 'label' => 'Number of Dependents', 'type' => 'number', 'required' => true],
        ['name' => 'support_reason', 'label' => 'Support Justification', 'type' => 'textarea', 'required' => true],
        ['name' => 'agreement_needs', 'label' => 'Declaration', 'type' => 'agreement', 'required' => true, 'help_text' => '<p>I confirm this financial information is truthful.</p>'],
        ['name' => 'thank_you_needs', 'label' => 'Thank You', 'type' => 'thank_you', 'help_text' => '<p>Thank you. Your request will be reviewed soon.</p>'],
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
