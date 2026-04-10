import React from 'react';
import { motion } from 'framer-motion';
import { Code, Palette, Users, MessageSquare } from 'lucide-react';
export function PremiumFeatures() {
  const features = [
  {
    icon: <Code size={28} />,
    title: 'Embeddable Widget',
    description:
    'One line of JavaScript to add the booking flow directly to your existing website.',
    comingSoon: true
  },
  {
    icon: <Palette size={28} />,
    title: 'Restaurant Branding',
    description:
    'Upload your logo and set brand colours for guest-facing pages and emails.',
    comingSoon: false
  },
  {
    icon: <Users size={28} />,
    title: 'Auto-Confirm by Size',
    description:
    'Automatically approve small parties while keeping manual review for larger groups.',
    comingSoon: true
  },
  {
    icon: <MessageSquare size={28} />,
    title: 'SMS Notifications',
    description:
    'Text confirmations and reminders via SMS to reduce no-shows even further.',
    comingSoon: true
  }];

  return (
    <section className="py-24 bg-forest text-cream">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="mb-16">
          <span className="inline-block px-4 py-1.5 bg-terracotta text-white font-semibold rounded-full text-sm mb-4">
            Premium Plan
          </span>
          <h2 className="text-3xl md:text-4xl font-bold mb-4">
            Professional tools for serious operators
          </h2>
          <p className="text-lg text-cream/70 max-w-2xl">
            Fully white-labeled booking experiences and advanced automation.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
          {features.map((feature, index) =>
          <motion.div
            key={index}
            initial={{
              opacity: 0,
              y: 20
            }}
            whileInView={{
              opacity: 1,
              y: 0
            }}
            viewport={{
              once: true
            }}
            transition={{
              delay: index * 0.1,
              duration: 0.5
            }}
            className="bg-forest-light/30 p-8 rounded-2xl border border-forest-light relative">
            
              <div className="text-terracotta mb-6">{feature.icon}</div>
              <h3 className="text-xl font-bold mb-3">{feature.title}</h3>
              <p className="text-cream/70 leading-relaxed mb-4">
                {feature.description}
              </p>

              {feature.comingSoon &&
            <span className="inline-block px-3 py-1 bg-forest text-cream/60 text-xs font-bold rounded-md uppercase tracking-wider border border-forest-light">
                  Coming Soon
                </span>
            }
            </motion.div>
          )}
        </div>
      </div>
    </section>);

}