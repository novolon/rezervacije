import React from 'react';
import { motion } from 'framer-motion';
import { Mail, Link as LinkIcon, CheckSquare } from 'lucide-react';
export function AdvancedFeatures() {
  const features = [
  {
    icon: <Mail size={28} />,
    title: 'Guest Email Notifications',
    description:
    'Automatic confirmation emails and 24-hour reminders sent directly to your guests.',
    comingSoon: false
  },
  {
    icon: <LinkIcon size={28} />,
    title: 'Public Booking Link',
    description:
    'Give guests a unique URL to book directly without calling. No more missed reservations.',
    comingSoon: true
  },
  {
    icon: <CheckSquare size={28} />,
    title: 'Approval Flow',
    description:
    'Review incoming requests and manually approve or reject them before confirming.',
    comingSoon: true
  }];

  return (
    <section className="py-24 bg-white border-t border-sage-light/50">
      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="mb-16">
          <span className="inline-block px-4 py-1.5 bg-forest/10 text-forest font-semibold rounded-full text-sm mb-4">
            Advanced Plan
          </span>
          <h2 className="text-3xl md:text-4xl font-bold text-forest mb-4">
            Keep your guests in the loop
          </h2>
          <p className="text-lg text-forest/70 max-w-2xl">
            Automate your communication and let guests book themselves online.
          </p>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
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
            className="bg-cream p-8 rounded-2xl border border-sage-light relative overflow-hidden">
            
              <div className="text-forest mb-6">{feature.icon}</div>
              <h3 className="text-xl font-bold text-forest mb-3 flex items-center gap-3">
                {feature.title}
              </h3>
              <p className="text-forest/70 leading-relaxed mb-4">
                {feature.description}
              </p>

              {feature.comingSoon &&
            <span className="inline-block px-3 py-1 bg-sage/30 text-forest/80 text-xs font-bold rounded-md uppercase tracking-wider">
                  Coming Soon
                </span>
            }
            </motion.div>
          )}
        </div>
      </div>
    </section>);

}