import React, { useState, useEffect } from 'react';
import { motion } from 'framer-motion';
import { Check } from 'lucide-react';

const APP_URL = import.meta.env.VITE_APP_URL ?? '';

interface Discount {
  label: string;
  discounted_monthly: number | null;
  discounted_yearly: number | null;
  valid_until: string;
}

interface PlanData {
  name: string;
  monthly_price: number;
  yearly_price: number;
  discount: Discount | null;
}

interface ApiPlans {
  basic: PlanData;
  advanced: PlanData;
  premium: PlanData;
}

const DEFAULT_PLANS = {
  basic:    { name: 'Basic',    monthly_price: 4.99, yearly_price: 49.99, discount: null },
  advanced: { name: 'Advanced', monthly_price: 6.99, yearly_price: 69.99, discount: null },
  premium:  { name: 'Premium',  monthly_price: 9.99, yearly_price: 99.99, discount: null },
};

const PLAN_FEATURES: Record<string, string[]> = {
  basic:    [
    'Reservation management',
    'Multiple restaurants',
    'Unlimited staff accounts',
    'Real-time calendar',
    'Email support',
  ],
  advanced: [
    'Everything in Basic',
    'Guest email notifications',
    '24h guest reminders',
    'Public booking link',
    'Approve / reject bookings',
    'Guest database & history',
    'Waitlist management',
    'Table management',
  ],
  premium:  [
    'Everything in Advanced',
    'Embeddable booking widget',
    'Restaurant branding',
    'Auto-confirm with guest limits',
    'SMS notifications',
    'Satisfaction surveys + CSV export',
  ],
};

function fmt(price: number): string {
  return '€' + price.toFixed(2).replace('.', ',');
}

export function PricingSection() {
  const [isYearly, setIsYearly] = useState(false);
  const [plans, setPlans] = useState<ApiPlans>(DEFAULT_PLANS);

  useEffect(() => {
    fetch(`${APP_URL}/api/pricing.php`)
      .then(r => r.json())
      .then(json => { if (json.success && json.data) setPlans(json.data); })
      .catch(() => { /* ostanejo default cene */ });
  }, []);

  const cards = [
    {
      slug: 'basic',
      name: plans.basic.name,
      price: fmt(isYearly
        ? (plans.basic.discount?.discounted_yearly  ?? plans.basic.yearly_price)
        : (plans.basic.discount?.discounted_monthly ?? plans.basic.monthly_price)),
      origPrice: isYearly
        ? (plans.basic.discount?.discounted_yearly  != null ? fmt(plans.basic.yearly_price)  : null)
        : (plans.basic.discount?.discounted_monthly != null ? fmt(plans.basic.monthly_price) : null),
      period: isYearly ? '/year' : '/month',
      discount: plans.basic.discount,
      description: 'Perfect for single locations moving off paper.',
      features: PLAN_FEATURES.basic,
      cta: 'Start Free Trial',
      highlight: false,
      href: `${APP_URL}/register.php?plan=basic`,
    },
    {
      slug: 'advanced',
      name: plans.advanced.name,
      price: fmt(isYearly
        ? (plans.advanced.discount?.discounted_yearly  ?? plans.advanced.yearly_price)
        : (plans.advanced.discount?.discounted_monthly ?? plans.advanced.monthly_price)),
      origPrice: isYearly
        ? (plans.advanced.discount?.discounted_yearly  != null ? fmt(plans.advanced.yearly_price)  : null)
        : (plans.advanced.discount?.discounted_monthly != null ? fmt(plans.advanced.monthly_price) : null),
      period: isYearly ? '/year' : '/month',
      discount: plans.advanced.discount,
      description: 'For restaurants that want online bookings and guest tools.',
      features: PLAN_FEATURES.advanced,
      cta: 'Start Free Trial',
      highlight: true,
      href: `${APP_URL}/register.php?plan=advanced`,
    },
    {
      slug: 'premium',
      name: plans.premium.name,
      price: fmt(isYearly
        ? (plans.premium.discount?.discounted_yearly  ?? plans.premium.yearly_price)
        : (plans.premium.discount?.discounted_monthly ?? plans.premium.monthly_price)),
      origPrice: isYearly
        ? (plans.premium.discount?.discounted_yearly  != null ? fmt(plans.premium.yearly_price)  : null)
        : (plans.premium.discount?.discounted_monthly != null ? fmt(plans.premium.monthly_price) : null),
      period: isYearly ? '/year' : '/month',
      discount: plans.premium.discount,
      description: 'Professional tools, full branding and automation.',
      features: PLAN_FEATURES.premium,
      cta: 'Start Free Trial',
      highlight: false,
      href: `${APP_URL}/register.php?plan=premium`,
    },
  ];

  return (
    <section id="pricing" className="py-24 bg-white">
      <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="text-center mb-16">
          <h2 className="text-3xl md:text-4xl font-bold text-forest mb-4">
            Simple, transparent pricing
          </h2>
          <p className="text-forest/60 text-lg mb-8">
            Try any plan free for 30 days — no credit card required.
            Switch between plans freely during your trial.
          </p>

          {/* Toggle */}
          <div className="flex items-center justify-center gap-4">
            <span className={`text-sm font-medium ${!isYearly ? 'text-forest' : 'text-forest/50'}`}>
              Monthly
            </span>
            <button
              onClick={() => setIsYearly(!isYearly)}
              className="relative w-14 h-8 bg-forest rounded-full p-1 transition-colors">
              <motion.div
                className="w-6 h-6 bg-white rounded-full shadow-sm"
                animate={{ x: isYearly ? 24 : 0 }}
                transition={{ type: 'spring', stiffness: 500, damping: 30 }} />
            </button>
            <span className={`text-sm font-medium flex items-center gap-2 ${isYearly ? 'text-forest' : 'text-forest/50'}`}>
              Yearly
              <span className="bg-terracotta/10 text-terracotta text-xs px-2 py-0.5 rounded-full font-bold">
                ~17% off
              </span>
            </span>
          </div>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-8 mb-12">
          {cards.map((plan, index) =>
            <motion.div
              key={plan.slug}
              initial={{ opacity: 0, y: 20 }}
              whileInView={{ opacity: 1, y: 0 }}
              viewport={{ once: true }}
              transition={{ delay: index * 0.1, duration: 0.5 }}
              className={`relative flex flex-col p-8 rounded-3xl border ${plan.highlight ? 'border-terracotta shadow-xl shadow-terracotta/10 bg-cream' : 'border-sage-light bg-white'}`}>

              {plan.highlight &&
                <div className="absolute top-0 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-terracotta text-white px-4 py-1 rounded-full text-sm font-bold shadow-sm">
                  Popular
                </div>
              }

              {/* 30-day trial badge */}
              <div className="inline-flex items-center gap-1.5 bg-sage/15 text-sage-dark text-xs font-semibold px-3 py-1 rounded-full mb-4 self-start">
                <Check size={12} className="text-sage" />
                30-day free trial
              </div>

              <div className="mb-6">
                <h3 className="text-xl font-bold text-forest mb-2">{plan.name}</h3>
                <p className="text-forest/60 text-sm">{plan.description}</p>
              </div>

              <div className="mb-1">
                <div className="flex items-baseline gap-2 flex-wrap">
                  {plan.origPrice &&
                    <span className="text-lg text-forest/40 line-through">{plan.origPrice}</span>
                  }
                  <span className="text-4xl font-bold text-forest">{plan.price}</span>
                  <span className="text-forest/60 font-medium">{plan.period}</span>
                </div>
              </div>

              {plan.discount && !isYearly && plan.discount.discounted_monthly != null &&
                <div className="text-xs text-terracotta font-semibold mb-4">
                  {plan.discount.label} – until {plan.discount.valid_until.slice(0, 7).replace('-', '/')}
                </div>
              }
              {plan.discount && isYearly && plan.discount.discounted_yearly != null &&
                <div className="text-xs text-terracotta font-semibold mb-4">
                  {plan.discount.label} – until {plan.discount.valid_until.slice(0, 7).replace('-', '/')}
                </div>
              }
              {(!plan.discount || (isYearly ? plan.discount.discounted_yearly == null : plan.discount.discounted_monthly == null)) &&
                <div className="mb-4" />
              }

              <ul className="space-y-3 mb-8 flex-1">
                {plan.features.map((feature, i) =>
                  <li key={i} className="flex items-start gap-3">
                    <Check size={18} className="text-sage shrink-0 mt-0.5" />
                    <span className="text-forest/80 text-sm">{feature}</span>
                  </li>
                )}
              </ul>

              <a
                href={plan.href}
                className={`w-full py-3 rounded-xl font-bold text-center transition-colors ${plan.highlight ? 'bg-terracotta hover:bg-terracotta-hover text-white' : 'bg-forest/10 hover:bg-forest/20 text-forest'}`}>
                {plan.cta}
              </a>
            </motion.div>
          )}
        </div>

        <div className="text-center text-forest/60 text-sm space-y-2">
          <p className="font-medium text-forest/80">
            All plans include unlimited staff accounts and multi-restaurant support.
          </p>
          <p>
            After the 30-day trial, choose and pay for the plan that suits you best.
            Upgrade at any time — you only pay the prorated difference.
          </p>
          <p>
            Yearly billing also available by invoice —{' '}
            <a href={`${APP_URL}/register.php`} className="underline hover:text-terracotta">
              contact us
            </a>
            .
          </p>
        </div>
      </div>
    </section>);
}
